<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        $testimonials = $posts = [];
        try {
            $testimonials = db_connect()->table('testimonials')->where('approved', 1)->orderBy('created_at', 'DESC')->limit(6)->get()->getResultArray();
            $posts = db_connect()->table('blog_posts')->orderBy('published_at', 'DESC')->limit(4)->get()->getResultArray();
        } catch (\Throwable $e) {
            // The public website remains available before the database is configured.
        }
        return view('home', ['testimonials' => $testimonials, 'posts' => $posts]);
    }
}
