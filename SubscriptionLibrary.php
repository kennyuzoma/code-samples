<?php

namespace Billing\PaymentGateways\Stripe;

use Carbon\Carbon;
use Stripe\StripeClient;
use Billing\Events\Restored;
use Billing\Events\SubscriptionCancelled;
use Billing\Events\SubscriptionPaused;
use Billing\Events\SubscriptionResumed;
use Billing\Events\SubscriptionStarted;
use Billing\Events\SubscriptionSwapped;
use Billing\Events\SubscriptionUpdated;
use Billing\Extenders\SubscriptionBase;
use Billing\Contracts\SubscriptionInterface;
use Billing\Exceptions\SubscriptionException;
use Billing\Helpers\BillingHelper;
use Billing\Models\Plan;
use Billing\Models\SubscriptionSchedule;

/**
 * Class Subscription
 *
 * @package Billing\PaymentGateways\Stripe
 */
class SubscriptionLibrary extends SubscriptionBase implements SubscriptionInterface  {

    /**
     * The stripe instance
     * @var StripeClient
     */
    private $stripe;

    /**
     * The plan id
     * @var int
     */
    public int $plan_id;

    /**
     * The plan object
     * @var object
     */
    public object $plan;

    /**
     * The quantity or number of plans requested
     * @var int
     */
    public int $quantity = 1;

    /**
     * Stripe credit value for a customer
     * @var int
     */
    public int $credit_amount = 0;

    /**
     * Sets the balance thats to be transfered
     *
     * @var int
     */
    public $transfer_balance = 0;

    /**
     * Sets the trial information for stripe
     * @var null
     */
    public $trial_until = null;

    /**
     * The subscription within this context
     *
     * @var object
     */
    public $subscription = null;

    /**
     * The User object
     *
     * @var object
     */
    public object $user;

    /**
     * The user's current subscription
     *
     * @var object
     */
    public $current_subscription;

    /**
     * Stripe proration behavior
     *
     * @var string[]
     */
    private $stripe_proration_behavior = [
        true => 'create_prorations',
        false => 'none'
    ];

    /**
     * SubscriptionManager constructor.
     * @TODO - allow $user to be passed in
     */
    public function __construct($actions = null)
    {
        $this->setBillingActions($actions);

        $this->stripe = new StripeClient(config('services.stripe.secret'));
        $this->user = auth()->user();
        $this->current_subscription = $this->user->subscription();
    }

    /**
     * Starts a new plan
     *
     * @param null $payment_method
     *
     * @return mixed|void
     * @throws SubscriptionException
     */
    public function start($payment_method = null)
    {
        $this->validateHasPaymentMethod($payment_method);

//        $this->validateCanHaveMultipleSubscriptions();

        $stripe_plan_id = config('custom.plans.'.$this->plan->tier)->pricing->{$this->plan->type}->stripe_id;

        // add credit
        $this->processAddCredit();

        $trial_until = $this->trial_until;
        if (!is_null($this->trial_until)) {
            $trial_until = Carbon::parse($this->trial_until);
        }

        // create the subscription on Stripe
        $subscription = $this->user
            ->newSubscription('main', $stripe_plan_id)
            ->quantity($this->quantity)
            ->trialUntil($trial_until)
            ->create($payment_method);

        $this->user->clearBalance();

        // update the user's information
        $this->forceFillUserPlanInfo('stripe', $this->plan_id, true);

        $starts_at = $this->trial_until ?? $this->user->subscription()->created_at;

        // update the subscription details
        $this->user->subscription()->update([
            'payment_gateway' => 'stripe',
            'plan_id' => $this->plan_id,
            'starts_at' => $starts_at
        ]);

        $this->user->clearBalance();

        $this->updateDefaultPaymentMethod($payment_method);

        event(new SubscriptionStarted($subscription));
    }

    /**
     * Creates a plan that will be scheduled
     *
     * @param null $payment_method
     *
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function scheduledStart($payment_method = null)
    {
        $stripe_plan_id = config('custom.plans.'.$this->plan->tier)->pricing->{$this->plan->type}->stripe_id;

        $current_subscription = $this->user->subscription();

        // create the subscription on Stripe
        $scheduled_subscription = $this->user
            ->newSubscription('main', $stripe_plan_id)
            ->quantity($this->quantity)
            ->trialUntil($this->trial_until)
            ->create($payment_method);

        $stripe_subscription = $this->stripe->subscriptions->retrieve($scheduled_subscription->stripe_id);

        $starts_at = date('Y-m-d H:i:s', $stripe_subscription->current_period_end);

        // record the scheduled change
        SubscriptionSchedule::create([
            'payment_gateway' => 'stripe',
            'user_id' => $this->user->id,
            'subscription_id' => $current_subscription->id,
            'starts_at' => date('Y-m-d H:i:s', $stripe_subscription->current_period_end),
            'data' => [
                'stripe_pending_sub_id' => $stripe_subscription->id
            ],
            'plan_id' => $this->plan->id,
            'quantity' => $this->quantity
        ]);

        // TODO - remove this and do a raw submit straight to stripe
        $scheduled_subscription->forceDelete();
    }

    /**
     * Updates a plan
     *
     * @return mixed|void
     */
    public function upgrade($plan, $quantity)
    {
        $stripe_subscription = $this->stripe->subscriptions->retrieve($this->user->subscription()->stripe_id);

        // "delete" the schedule on stripe's end and on our end
        if (!is_null($schedule = $this->current_subscription->schedule->first())) {
            $class = BillingHelper::paymentGatewayClass($schedule->payment_gateway);
            (new $class)->cancelSchedule();
        } else {
            $upgrade_items['proration_behavior'] = $this->stripe_proration_behavior[$this->prorate_now];
        }

        $upgrade_items['items'] = [
            [
                'id' => $stripe_subscription->items->data[0]->id,
                'price' => $plan->pricing->stripe_id,
                'quantity' => $quantity
            ],
        ];

        // todo - record the upgrade in the database
        $this->stripe->subscriptions->update($this->user->subscription()->stripe_id, [$upgrade_items]);

        if ($this->invoice_now) {
            $this->doInvoiceUserNow();
        }

        $this->current_subscription->forceFill([
            'quantity' => $quantity,
            'plan_id' => $plan->id,
        ])->save();

        $this->user->clearBalance();

        $this->forceFillUserPlanInfo('stripe', $plan->id, true);

        $this->checkAndApplyBalance();

        event(new SubscriptionUpdated());
    }

    /**
     * Downgrades a plan. Downgrades are never prorated and are scheduled
     * @param $plan
     * @param $quantity
     *
     * @return mixed|void
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function downgrade($plan, $quantity)
    {
        $stripe_subscription = $this->stripe->subscriptions->retrieve($this->current_subscription->stripe_id);

        // "delete" any existing schedule on stripe's end and on our end
        if (!is_null($schedule = $this->current_subscription->schedule->first())) {
            $this->stripe->subscriptionSchedules->release($schedule->data->stripe_schedule_id);
            $schedule->delete();
        }

        // we will SCHEDULE a change
        $subscription_schedule = $this->stripe->subscriptionSchedules->create([
            'from_subscription' => $this->current_subscription->stripe_id,
        ]);

        $period_end = $this->getSubscriptionEndTime($stripe_subscription->current_period_end);

        // record the scheduled change
        SubscriptionSchedule::create([
            'payment_gateway' => 'stripe',
            'user_id' => $this->user->id,
            'subscription_id' => $this->current_subscription->id,
            'starts_at' => date('Y-m-d H:i:s', $period_end),
            'data' => [
                'stripe_schedule_id' => $subscription_schedule->id
            ],
            'plan_id' => $plan->id,
            'quantity' => $quantity
        ]);

        // update subscription schedule
        $this->stripe->subscriptionSchedules->update($subscription_schedule->id, [
            'phases' => [
                [
                    'items' => [
                        [
                            'price' => $this->current_subscription->plan->pricing->stripe_id,
                            'quantity' => $this->current_subscription->quantity,
                        ],
                    ],
                    'proration_behavior' => $this->stripe_proration_behavior[$this->prorate_now],
                    'start_date' => $stripe_subscription->current_period_start,
                    'end_date' => $period_end
                ],
                [
                    'items' => [
                        [
                            'price' => $plan->pricing->stripe_id,
                            'quantity' => $quantity,
                        ],
                    ],
                    'iterations' => 1,
                    'proration_behavior' => $this->stripe_proration_behavior[$this->prorate_now],
                    'start_date' => $period_end
                ],
            ]
        ]);
    }

    /**
     * Invoices a user now... which also charges them via stripe
     *
     * @throws \Stripe\Exception\ApiErrorException
     */
    private function doInvoiceUserNow()
    {
        // create an invoice
        $invoice = $this->stripe->invoices->create([
            'customer' => $this->user->stripe_id,
            'subscription' => $this->user->subscription()->stripe_id,
            'auto_advance' => TRUE
        ]);

        // charge the invoice
        try {
            $this->stripe->invoices->pay(
                $invoice->id,
                []
            );
        } catch (\Exception $e) {
            dd($e->getMessage()); // TODO - TEST THIS!!!
        }
    }

    /**
     * Swaps the plan
     *
     * @param null $payment_method
     *
     * @throws SubscriptionException
     */
    public function swap($payment_method = null)
    {
        $current_subscription = $this->user->subscription();
        $payment_gateway_plan_id = $this->plan->stripe_id;

        $this->validateHasPaymentMethod($payment_method);

        // cancel plan via gateway
        $canceled_plan = $this->user
            ->paymentGateway($current_subscription->payment_gateway)
            ->cancel(true, $this->plan_id);

        // set the new plan to be started later
        try {
            $this->user->newSubscription('main', $payment_gateway_plan_id)
                ->trialUntil($canceled_plan['ends_at'])
                ->quantity($this->quantity)
                ->create($payment_method);
        } catch(\Exception $e) {
            throwCustomException($e, 'There was an error swaping this subscription. We have been alerted. Please try again later.');
        }

        $current_subscription->delete();

        event(new SubscriptionSwapped());
    }

    /**
     * Cancels the plan
     *
     * @param false $now
     * @param null $next_plan_id
     * @param false $swapped
     *
     * @return array
     */
    public function cancel($now = false, $next_plan_id = null, $swapped = false)
    {
        $subscription = $this->subscription ?? $this->user->subscription();
        // cancel the subscription
        if ($now === true) {
            $ends_at = self::getNextBillingTime()['raw'];
            $subscription->cancelNow();
        } else {
            $ends_at = $subscription->ends_at;
            $subscription->cancel();
        }

        // TODO ... is this necessary?
        if (!is_null($next_plan_id)) {
            $subscription->next_plan_id = $next_plan_id;
            $subscription->save();
        }

        // TODO do we ever use $swapped?
        if ($swapped === true) {
            $subscription->swapped = true;
            $subscription->save();
        }

        return ['ends_at' => $ends_at];

        event(new SubscriptionCancelled());
    }

    /**
     * Pauses the plan
     *
     * @return mixed|void
     */
    public function pause()
    {
        event(new SubscriptionPaused());
    }

    /**
     * Resumes the plan
     *
     * @return mixed
     */
    public function resume()
    {
        $current_subscription = $this->user->subscription();

        if (!is_null($current_subscription) && $current_subscription->onGracePeriod()) {
            try {
                $this->user->subscription('main')->resume();
            } catch(\Exception $e) {
                report($e);
                throwCustomException($e, 'There was an issue trying to resume your plan. We have been notified. Please try again later.');
            }

            $current_subscription->next_plan_id = null;
            $current_subscription->ends_at = null;
            $current_subscription->save();
        }

        event(new SubscriptionResumed($current_subscription));

        return $current_subscription;
    }

    /**
     * Returns the next billing time
     *
     * @return array|null
     */
    public function getNextBillingTime($future_time = null)
    {
        $current_subscription = $this->subscription ?? $this->user->subscription();

        if (is_null($current_subscription)) {
            $return = null;
        } else {
            if (!is_null($current_subscription->trial_ends_at)) {
                $next_billing_time = $current_subscription->trial_ends_at;
                $next_billing_time_zulu = $next_billing_time->format('Y-m-d\TH:i:s\Z');
            } else {
                $time = new Carbon($current_subscription->asStripeSubscription()->current_period_end);
                $next_billing_time = $time;
                $next_billing_time_zulu = $time->format('Y-m-d\TH:i:s\Z');
            }

            if (!is_null($future_time)) {
                $time = new Carbon($future_time);
                $next_billing_time = $time;
                $next_billing_time_zulu = $time->format('Y-m-d\TH:i:s\Z');
            }

            $human = $next_billing_time->format('F j, Y');
            $return = [
                'zulu' => $next_billing_time_zulu,
                'human' => $human,
                'raw' => $next_billing_time,
                'ymdhis' => $next_billing_time->format('Y-m-d H:i:s'),
                'will_be_billed_on' => "will be billed on " . $human
            ];
        }

        return $return;
    }

    /**
     * @TODO - handle if this is going from 1 month to 2 annual
     * @param $requested_quantity
     * @param $tier
     * @param $cycle
     *
     * @return float|int
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function getProration($requested_quantity, $tier, $cycle)
    {
        $this->setAndBuildBillingActions();

        // 1. get the proration preview
        try {
            $slug = $tier . '_' . $cycle;

            $plan_pricing_info = Plan::where('slug', $slug)->first()->pricing;

            $stripe = new StripeClient(config('services.stripe.secret'));
            $subscription = $stripe->subscriptions->retrieve($this->user->subscription()->stripe_id);

            $upcoming_info = [
                'customer' => $this->user->stripe_id,
                'subscription' => $this->user->subscription()->stripe_id,
                'subscription_items' => [
                    [
                        'id' => $subscription->items->data[0]->id,
                        'price' => $plan_pricing_info->stripe_id,
                        'quantity' => $requested_quantity
                    ],
                ],
            ];

            $upcoming_info['subscription_proration_behavior'] = 'always_invoice';

//            if (
//                $this->user->subscription()->plan->type == 'annual' && $cycle == 'monthly'
//                &&
//                $this->user->subscription()->quantity < $requested_quantity
//            ) {
//                $upcoming_info['subscription_proration_behavior'] = 'none';
//            }

            $upcoming = $stripe->invoices->upcoming($upcoming_info);

            $return = $upcoming->total;

        } catch(\Exception $e) {
            $return = BillingHelper::calculateProration($requested_quantity, $tier, $cycle);
        }

        return $return;
    }

    /**
     * Updates the users default payment method
     *
     * @param null|string $payment_method
     */
    private function updateDefaultPaymentMethod($payment_method = null)
    {
        if (is_null($payment_method)) {
            $paymentMethod = $this->user->defaultPaymentMethod();
        } else {
            $paymentMethod = $this->user->findPaymentMethod($payment_method);
        }

        $this->user->updateDefaultPaymentMethod($paymentMethod->id);
    }

    /**
     * Validates that a user has a payment method
     *
     * @param string $payment_method
     *
     * @throws SubscriptionException
     */
    private function validateHasPaymentMethod(string $payment_method = null)
    {
        if (is_null($payment_method) && is_null($this->user->card_last_four)) {
            throw new SubscriptionException('There was an issue processing your payment. Please enter your details again.');
        }
    }

    public function addCredit($amount)
    {
        $this->credit_amount = $amount;

        return $this;
    }

    public function trialUntil($date = null)
    {
        $this->trial_until = $date;

        return $this;
    }

    public function processAddCredit()
    {
        if ($this->credit_amount > 0) {
            $customer_id = $this->user->stripe_id;

            if (is_null($customer_id)) {
                $customer = $this->createCustomer([
                    'email' => $this->user->email,
                    ''
                ]);

                $this->user->forceFill([
                    'stripe_id' => $customer->id
                ])->save();

                $customer_id = $customer->id;
            }

            $balance_transaction = $this->stripe->customers->createBalanceTransaction(
                $customer_id,
                ['amount' => -($this->credit_amount), 'currency' => 'usd']
            );

            return $balance_transaction;
        }
    }

    public function createCustomer(array $info)
    {
        return $this->stripe->customers->create($info);
    }

    public function subscription($subscription)
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function transferBalance($balance)
    {
        $this->transfer_balance = $balance;

        return $this;
    }

    public function cancelSchedule()
    {
        $subscription = $this->subscription ?? auth()->user()->subscription();

        if (!$schedule = $subscription->schedule->first()) {
            throw new \Exception('There is no pending schedule change for this subscription');
        }

        // TODO - use schedules only instead of hybrid of subscriptions and schedules?
        if (isset($schedule->data->stripe_schedule_id)) {
            if ($this->stripe->subscriptionSchedules->release($schedule->data->stripe_schedule_id)) {
                $schedule->delete();
            }
        } elseif (isset($schedule->data->stripe_pending_sub_id)) {
            if ($this->stripe->subscriptions->cancel($schedule->data->stripe_pending_sub_id)) {
                $schedule->delete();
            }
        }
    }

    /**
     * Checks if the user has a balance on stripe
     */
    private function checkAndApplyBalance()
    {
        $customer = $this->stripe->customers->retrieve($this->user->stripe_id);

        if ($customer->balance < 0) {
            $this->user->addBalance($customer->balance);
        }
    }

    public function customer()
    {
        return $this->stripe
            ->customers
            ->retrieve($this->user->stripe_id);
    }

    public function balance()
    {
        return $this->customer()
            ->balance;
    }

    public function pullBalance()
    {
        if ($this->balance() < 0) {
            $this->user->balance += $this->balance();
            $this->user->save();
        }
    }

    public function getExternalBalance(): int
    {
        return $this->customer()->balance;
    }

}
