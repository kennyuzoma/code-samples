<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ContentCollection;
use App\Http\Traits\SnapshotTrait;
use UzoUniverse\User\Helpers\SettingsHelper;
use App\Http\Requests\ChangeUrlRequest;
use App\Http\Requests\Page\StoreRequest;
use App\Http\Requests\Page\UpdateRequest;
use App\Http\Resources\PageCollection;
use App\Http\Resources\PageResource;
use App\Models\Page;
use App\Models\Slug;
use App\Models\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Content;

class FeatureApiController extends BaseController
{
    use SnapshotTrait;

    protected $except = [
        '_token',
        '_method',
        'request_type',
        'type',
        'content_id',
        'page_id',
        'url'
    ];

    /**
     * Create the controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->authorizeResource(Page::class, 'page');
    }

    public function index()
    {
        $pages = auth()->user()->pages()->orderBy('id', 'DESC')->paginate();

        return new PageCollection($pages);
    }

    public function show(Request $request, Page $page)
    {
        return new PageResource($page);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreRequest $request)
    {
        $user = auth()->user();

        $theme = Theme::find(rand(6,9));

        $page = Page::create([
            'user_id' => $user->id,
            'settings' => [
                'title' => '@' . $request->get('slug'),
                'description' => '',
                'display_verified' => false,
                'google_analytics' => '',
                'facebook_pixel' => '',
                'display_branding' => false,
                'branding' => [
                    'url' => '',
                    'name' => '',
                    'display' => 'off'
                ],
                'seo' => [
                    'title' => '',
                    'meta_description' => ''
                ],
                'utm' => [
                    'medium' => '',
                    'source' => '',
                ],
                'socials' => [],
                'font' => null
            ],
            'theme_id' => $theme->id,
            'style' => $theme->style,
            // Todo for SQL lite testing
            'clicks' => 0
        ]);

        $page->users()->attach($user,[
            'primary_admin' => 1
        ]);

        $page->slug()->create([
            'name' => $request->get('slug')
        ]);

        // todo cache

        // inital first link
        $content = Content::create([
            'page_id' => $page->id,
            'user_id' => $user->id,
            'type' => 'link',
            'url' => config('app.url'),
            'settings' => [
                'name' => $request->get('name') ?? __('Your own link here'),
                'animation' => false,
            ],
            // TODO because of SQLite
            'clicks' => 0,
            'order' => 0
        ]);

        $content->slug()->create([
            'name' => Slug::generate()
        ]);

        // todo cache link
        // todo clear cache

        // cache the link
        return response()->json($page, 201);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Page  $page
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateRequest $request, Page $page)
    {
        if (
            $request->has('background_type')
            &&
            $request->get('background_type') == 'image'
            &&
            !$request->hasFile('background_image')
            &&
            $page->getFirstMediaUrl('background_image') == ''
        ) {
            return response()->json([
                'message' => ['Please select an image to upload'],
                'status' => 'error',
                'details' => [],
            ]);
        }

        /* Update the avatar of the profile if needed */

        if ($request->hasFile('page_image')) {
            $page
                ->addMediaFromRequest('page_image')
                ->setFileName(Str::orderedUuid() . '.jpg')
                ->toMediaCollection('page_image');
        }

        // delete the avatar
        $image_delete = $request->has('image_delete') && $request->get('image_delete') == 'true';

        if ($image_delete) {
            $page->clearMediaCollection('page_image');
        }
        $this->handleEditRequest($request);

        if ($request->has('style.background_type')) {
            $backgrounds = config('custom.app.backgrounds');
            $background_type = array_key_exists($request->get('style.background_type'), $backgrounds) ? $request->get('style.background_type') : 'preset';
            $background = 'one';
            switch ($background_type) {
                case 'preset':
                    $background = $request->get('style.background') ?? 'one';
                    break;

                case 'color':
                    $background = !preg_match('/#([A-Fa-f0-9]{3,4}){1,2}\b/i', $request->get('style.background')) ? '#000' : $request->get('style.background');
                    break;

                case 'gradient':
                    $color_one = !preg_match('/#([A-Fa-f0-9]{3,4}){1,2}\b/i', $request->get('style.background')[0]) ? '#000' : $request->get('style.background')[0];
                    $color_two = !preg_match('/#([A-Fa-f0-9]{3,4}){1,2}\b/i', $request->get('style.background')[1]) ? '#000' : $request->get('style.background')[1];

                    $background = [
                        'color_one' => $color_one,
                        'color_two' => $color_two
                    ];
                    break;

                case 'image':
                    $background_new_name = Str::orderedUuid() . '.jpg';
                    $background = $background_new_name;

                    if ($request->hasFile('style.background_image')) {
                        $page->clearMediaCollection('style.background_image');

                        $page
                            ->addMediaFromRequest('style.background_image')
                            ->setFileName($background_new_name)
                            ->toMediaCollection('style.background_image');
                    }

                    break;
            }
            $request->merge([
                'style.background' => $background ? $background : $page->style->background,
            ]);
        }

        if ($request->has('style.background_type')) {
            if ($request->get('style.background_type') != 'image') {
                $page->clearMediaCollection('style.background_image');
            }
        }

        // delete the background
        $background_image_delete = $request->has('style.background_image_delete') && $request->get('style.background_image_delete') == 'true';

        if ($background_image_delete) {
            $page->clearMediaCollection('style.background_image');
        }

        $this->processUpdate($request, $page);

        cache()->forget('page_' . $page->slug);

        return response()->json([
            'message' => [__('Update successful!')],
            'status' => 'success',
            'details' => [],
        ]);
    }

    public function processUpdate($request, $page)
    {
        $custom_style_fields = [
            'button',
            'background',
            'background_type',
            'background_image',
            'font',
            'branding'
        ];

        $this->except[] = 'url';
        $this->except[] = 'slug';

        $style_fields_to_be_processed = [];
        $settings_fields_to_be_processed = [];

        if ($request->has('settings')) {
            foreach ($request->except($this->except)['settings'] as $field => $value) {
                $settings_fields_to_be_processed[$field] = $value;
            }
        }

        if ($request->has('style')) {
            foreach ($request->except($this->except)['style'] as $field => $value) {
                $style_fields_to_be_processed[$field] = $value;

                // mark custom fields when they are edited
                if (in_array($field, $custom_style_fields) && $page->custom == 0) {
                    $page->custom = 1;
                }
            }
        }

        $style_to_update = SettingsHelper::flattenArray($style_fields_to_be_processed, 'style');
        $settings_to_update = SettingsHelper::flattenArray($settings_fields_to_be_processed, 'settings');

        $page->forceFill($style_to_update + $settings_to_update);

        if (!empty($style_to_update) && !is_null($page->theme_id)) {
            $page->theme_id = null;
        }

        $page->save();
    }

    public function destroy(Request $request, Page $page)
    {
        $this->authorize('view', $page);

        // TODO remove redirect convert to $this->authorize('viewPrimaryAdmin', $page);
        if (!$this->isPrimaryAdmin($page)) {
            return redirect(route('page',['page' => $page->id]))
                ->with('global_status', ['type' => 'error', 'message' => 'You can not do this']);
        }

        $page->content()->delete();
        $page->slug()->delete();
        $page->delete();

        return response()->json([
            'message' => [__('Delete successful')],
            'status' => 'success',
            'details' => [],
        ]);
    }

    public function reset(Request $request, Page $page)
    {
        $this->authorize('view', $page);

        // take snapshot
        $this->takeSnapshot('Saved Snapshot - ' . date('F j, Y H:i:sa'), $page);

        // delete content
        $page->content->each(function($content) {
            $content->delete();
        });

        $theme = Theme::find(rand(6,9));

        // reset page
        $page->settings = [
            'title' => '@' . $page->slug->name,
            'description' => '',
            'display_verified' => false,
            'google_analytics' => '',
            'facebook_pixel' => '',
            'display_branding' => false,
            'branding' => [
                'url' => '',
                'name' => '',
                'display' => 'off'
            ],
            'seo' => [
                'title' => '',
                'meta_description' => ''
            ],
            'utm' => [
                'medium' => '',
                'source' => '',
            ],
            'socials' => [],
            'font' => null
        ];

        $page->theme_id = $theme->id;
        $page->style = $theme->style;
        $page->custom = 0;
        $page->save();

        return response()->json([
            'message' => [__('Delete successful')],
            'status' => 'success',
            'details' => [],
        ]);
    }

    /**
     * TODO - Temporary Method
     *
     * @param Request $request
     * @param Page $page
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroyNonAjax(Request $request, Page $page)
    {
        $this->authorize('view', $page);

        // TODO remove redirect convert to $this->authorize('viewPrimaryAdmin', $page);
        if (!$this->isPrimaryAdmin($page)) {
            return redirect(route('page',['page' => $page->id]))
                ->with('global_status', ['type' => 'error', 'message' => 'You can not do this']);
        }

        $page->content()->delete();
        $page->slug()->delete();
        $page->delete();

        // TODO temporary
        return redirect(route('dashboard'))
            ->with('global_status', ['type' => 'success', 'message' => 'Page Sucessfully deleted']);
    }

    private function handleEditRequest(Request $request)
    {
        if ($request->has('font')) {
            $fonts = config('custom.app.fonts');
            $font = strtolower(str_replace(' ', '-', $request->get('font')));
            $request->merge([
                'font' => !array_key_exists($font, $fonts) ? false : $font
            ]);
        }

        if ($request->has('socials')) {
            $socials = config('custom.app.socials');
            foreach ($request->get('socials') as $key => $value) {

                if ($key == 'instagram') {
                    if (!empty($value) && $value[0] === '@') {

                        $value = str_replace('@', '', $value);
                        $request->merge(['socials' => [
                            'instagram' => $value
                        ]]);
                    }
                }

                if (!array_key_exists($key, $socials)) {
                    unset($request->get('socials')[$key]);
                }
            }

        }

        if ($request->has('text_color')) {
            $text_color = $request->get('text_color');
            $request->merge([
                'text_color' => !preg_match('/#([A-Fa-f0-9]{3,4}){1,2}\b/i', $text_color) ? '#fff' : $text_color
            ]);
        }

        if ($request->has('socials_color')) {
            $socials_color = $request->get('socials_color');
            $request->merge([
                'socials_color' => !preg_match('/#([A-Fa-f0-9]{3,4}){1,2}\b/i', $socials_color) ? '#fff' : $socials_color
            ]);
        }

        if ($request->has('display_verified')) {
            $request->merge([
                'display_verified' => $page->settings->display_verified ?? false
            ]);
        }

    }

    public function detach(Request $request)
    {
        $user = auth()->user();

        $page = $user->pages()->find($request->get('page_id'));

        if (is_null($page)) {
            return response()->json([
                'message' => ['You have no association to this'],
                'status' => 'error',
                'details' => [],
            ]);
        }

        $page->users()->detach($user->id);

        return response()->json([
            'message' => [],
            'status' => 'success',
            'details' => ['url' => route('dashboard')],
        ]);
    }

    public function toggle(Request $request, Page $page)
    {
        $user = auth()->user();

        $pages = $user->pages;

        foreach ($pages as $page) {

            if ($page->id == request()->get('page_id')) {

                $user->pages()->updateExistingPivot($page->id,[
                    'is_enabled' => (int) !$page->pivot->is_enabled
                ]);

            } else {
                if ($user->plan->tier == 'free') {
                    $user->pages()->updateExistingPivot($page->id, [
                        'is_enabled' => 0
                    ]);
                }
            }

            if ($page->user_id == $user->id) {
                if ($page->id == request()->get('page_id')) {
                    $page->is_enabled = (int) !$page->pivot->is_enabled;
                } else {
                    if ($user->plan->tier == 'free') {
                        $page->is_enabled = 0;
                    }
                }
                $page->save();
            }
        }

        return response()->json([
            'message' => [],
            'status' => 'success',
            'details' => [],
        ]);
    }

    public function choose()
    {
        $user = auth()->user();

        foreach ($user->pages as $page) {
            if ($page->id == request()->get('page_id')) {
                $user->pages()->updateExistingPivot($page->id,[
                    'is_enabled' => 1
                ]);
            } else {
                $user->pages()->updateExistingPivot($page->id,[
                    'is_enabled' => 0
                ]);
            }

            if ($page->user_id == $user->id) {
                if ($page->id == request()->get('page_id')) {
                    $page->is_enabled = 1;
                } else {
                    $page->is_enabled = 0;
                }
                $page->save();
            }
        }

        auth()->user()->forceFill([
            'settings->free_choose_page' => 1
        ])->save();

        return response()->json([
            'message' => ['Page Selected'],
            'status' => 'success',
            'details' => ['url' => route('dashboard')],
        ]);
    }

    public function changeSlug(ChangeUrlRequest $request, Page $page)
    {
        $user = auth()->user();

        if ($request->has('slug') && $request->get('slug') != $page->slug->name) {
            $current_user = $page->users()->where('user_id', $user->id)->first();
            if (!is_null($current_user->pivot->primary_admin)) {
                $page->slug->update([
                    'name' => $request->get('slug')
                ]);
            }
        }

        return response()->json();
    }

    public function isPrimaryAdmin($page)
    {
        return (bool) $page
            ->users()
            ->where('user_id', auth()->user()->id)
            ->first()
            ->pivot
            ->primary_admin;
    }

    public function getAllContent($pageId)
    {
        $content = Content::where('page_id', $pageId)
            ->orderBy('order', 'ASC')
            ->get();;

        return new ContentCollection($content);
    }

}
