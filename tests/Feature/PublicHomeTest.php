<?php

declare(strict_types=1);

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PublicHomeTest extends TestCase
{
    public function test_public_home_renders_bablys_inertia_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/home')
                ->where('brand.name', "Bably's Travel & Tour")
                ->has('categories', 4)
                ->etc()
            );
    }
}
