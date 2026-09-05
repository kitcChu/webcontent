<?php

namespace Kit\WebContent\Tests\Feature;

use Kit\WebContent\Models\WebContent;
use Kit\WebContent\Tests\TestCase;

class SitemapTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function the_sitemap_lists_all_web_pages(): void
    {
        WebContent::create([
            'slug' => 'main',
            'title' => 'Home',
            'content' => 'x',
            'is_web_page' => true,
        ]);
        WebContent::create([
            'slug' => 'services/packing',
            'title' => 'Packing',
            'content' => 'x',
            'is_web_page' => true,
        ]);
        WebContent::create([
            'slug' => 'a-form',
            'title' => 'Form fragment',
            'content' => '<form></form>',
            'is_web_page' => false,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $xml = $response->getContent();
        $this->assertStringContainsString('<loc>'.url('/').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.route('page.show', ['slug' => 'services/packing']).'</loc>', $xml);
        // Form fragments are excluded from the sitemap.
        $this->assertStringNotContainsString('a-form', $xml);
    }
}
