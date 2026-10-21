<?php

namespace App\Controllers;

class Home extends BaseController
{
    protected $helpers = ['form', 'url'];

    public function index()
    {
        $db = db_connect();

        return view('home', [
            'testimonials' => $db->table('testimonials')->where('approved', 1)->orderBy('id', 'DESC')->limit(6)->get()->getResultArray(),
            'posts'        => $db->table('blog_posts')->where('published_at IS NOT NULL', null, false)->orderBy('published_at', 'DESC')->limit(6)->get()->getResultArray(),
        ]);
    }
}
