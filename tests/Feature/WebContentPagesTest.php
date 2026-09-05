<?php

namespace Kit\WebContent\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Kit\WebContent\Models\WebContent;
use Kit\WebContent\Tests\TestCase;

class WebContentPagesTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function the_package_migration_creates_the_full_schema(): void
    {
        $columns = \Schema::getColumnListing('web_contents');

        foreach ([
            'id', 'slug', 'locale', 'title', 'head_meta', 'content', 'style',
            'script', 'attach_form_id', 'is_web_page', 'created_by', 'updated_by',
            'created_at', 'updated_at', 'deleted_at',
        ] as $column) {
            $this->assertContains($column, $columns, "Missing column: {$column}");
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_catch_all_route_serves_a_page_by_slug(): void
    {
        WebContent::create([
            'slug' => 'about-us',
            'title' => 'About Us',
            'content' => '<article><h1>About</h1></article>',
            'is_web_page' => true,
        ]);

        $response = $this->get('/about-us', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);

        $response->assertStatus(200);
        $response->assertJsonPath('component', 'CMS/StaticPage');
        $response->assertJsonPath('props.slug', 'about-us');
        $response->assertJsonPath('props.title', 'About Us');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_home_route_serves_the_home_slug_page(): void
    {
        WebContent::create([
            'slug' => 'main',
            'title' => 'Home',
            'content' => '<h1>Welcome home</h1>',
            'is_web_page' => true,
        ]);

        $this->get('/', ['X-Inertia' => 'true'])
            ->assertStatus(200)
            ->assertJsonPath('props.slug', 'main');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function form_fragments_are_not_served_as_pages(): void
    {
        WebContent::create([
            'slug' => 'enquiry-form',
            'title' => 'Enquiry form',
            'content' => '<form id="exportEnquiryForm"></form>',
            'is_web_page' => false, // fragment row
        ]);

        $this->get('/enquiry-form')->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function attached_form_content_is_passed_to_the_page(): void
    {
        $form = WebContent::create([
            'slug' => 'rate-form',
            'title' => 'Rate form',
            'content' => '<form id="exportEnquiryForm" data-x="__CSRF_TOKEN__"></form>',
            'is_web_page' => false,
        ]);

        WebContent::create([
            'slug' => 'london-rate',
            'title' => 'London Rate',
            'content' => '<article>Body</article>',
            'attach_form_id' => $form->id,
            'is_web_page' => true,
        ]);

        $this->get('/london-rate', ['X-Inertia' => 'true'])
            ->assertStatus(200)
            ->assertJsonPath('props.attach_form', $form->content);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function legacy_pages_urls_redirect_permanently(): void
    {
        WebContent::create([
            'slug' => 'terms',
            'title' => 'Terms',
            'content' => '<p>t&c</p>',
            'is_web_page' => true,
        ]);

        $this->get('/pages/terms')->assertRedirect('/terms');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_admin_editor_renders_for_authorized_users(): void
    {
        $page = WebContent::create([
            'slug' => 'main',
            'title' => 'Home',
            'content' => '<h1>Welcome home</h1>',
            'is_web_page' => true,
        ]);

        $this->get("/web-content/{$page->id}/edit", ['X-Inertia' => 'true'])
            ->assertStatus(200)
            ->assertJsonPath('component', 'CMS/WebContent')
            ->assertJsonPath('props.slug', 'main');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_admin_editor_is_blocked_when_the_gate_denies(): void
    {
        Gate::define('manage-web-content', fn ($user = null) => false);

        $page = WebContent::create([
            'slug' => 'main',
            'title' => 'Home',
            'content' => '<h1>Welcome home</h1>',
            'is_web_page' => true,
        ]);

        $this->get("/web-content/{$page->id}/edit")->assertStatus(403);
        $this->put("/web-content/{$page->id}", ['title' => 'x', 'content' => 'y'])->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_persists_changes_and_normalizes_locale_and_head_meta(): void
    {
        $page = WebContent::create([
            'slug' => 'main',
            'title' => 'Home',
            'content' => '<h1>Old</h1>',
            'is_web_page' => true,
        ]);

        $this->put("/web-content/{$page->id}", [
            'title' => 'Home (updated)',
            'slug' => 'main',
            'content' => '<h1>New</h1>',
            'style' => 'h1 { color: red; }',
            'script' => 'console.log(1);',
            'locale' => 'en',
            'head_meta' => json_encode(['description' => 'Meta description', 'canonical' => '/main']),
        ])->assertRedirect();

        $page->refresh();

        $this->assertSame('<h1>New</h1>', $page->content);
        $this->assertSame('en', $page->locale);
        $this->assertSame('Meta description', $page->head_meta['description']);
        $this->assertSame('/main', $page->head_meta['canonical']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_rejects_reserved_slugs(): void
    {
        $page = WebContent::create([
            'slug' => 'safe-page',
            'title' => 'Safe',
            'content' => 'x',
            'is_web_page' => true,
        ]);

        $this->from('/nowhere')->put("/web-content/{$page->id}", [
            'title' => 'Hijack',
            'slug' => 'orders/secret',
            'content' => 'x',
        ]);

        $this->assertDatabaseHas('web_contents', ['id' => $page->id, 'slug' => 'safe-page']);
        $this->assertDatabaseMissing('web_contents', ['slug' => 'orders/secret']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function invalid_head_meta_json_is_dropped_on_update(): void
    {
        $page = WebContent::create([
            'slug' => 'main',
            'title' => 'Home',
            'content' => 'x',
            'is_web_page' => true,
        ]);

        $this->put("/web-content/{$page->id}", [
            'title' => 'Home',
            'slug' => 'main',
            'content' => 'x',
            'head_meta' => '{not-valid-json',
        ])->assertRedirect();

        $this->assertNull($page->refresh()->head_meta);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function audit_columns_are_filled_from_the_authenticated_user(): void
    {
        config(['webcontent.user_model' => FakeUser::class]);

        // Simulate an authenticated user of the configured model.
        $user = new FakeUser(['id' => 7]);
        $this->be($user);

        $page = WebContent::create([
            'slug' => 'audited',
            'title' => 'Audited',
            'content' => 'x',
            'is_web_page' => true,
        ]);

        $this->assertSame(7, $page->created_by);
        $this->assertSame(7, $page->updated_by);
    }
}

/**
 * Minimal user model stand-in so tests do not depend on the host's User model.
 */
class FakeUser extends \Illuminate\Foundation\Auth\User
{
    protected $fillable = ['id'];
    protected $table = 'users';
}
