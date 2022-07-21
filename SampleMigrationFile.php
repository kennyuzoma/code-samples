<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBlogPostTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('blog_posts', function (Blueprint $table) {

            $table->increments('id');
            $table->string('uuid')->nullable()->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users');
            $table->string('title');
            $table->string('slug');
            $table->integer('views')->defalut(0);
            $table->boolean('enable_comments')->default('1');
            $table->integer('category_id')->nullable()->unsigned();
            $table->foreign('category_id')->references('id')->on('blog_categories');
            $table->string('featured_image')->nullable();
            $table->text('preview_text')->nullable();
            $table->text('body');
            $table->string('estimated_reading_time')->nullable();
            $table->string('status')->nullable();
            $table->datetime('published_at')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('blog_post');
    }
}
