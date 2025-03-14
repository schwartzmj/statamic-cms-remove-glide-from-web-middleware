<?php

namespace Tests\Data\Taxonomies;

use Facades\Statamic\Fields\BlueprintRepository;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Event;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\TaxonomyCreated;
use Statamic\Events\TaxonomySaved;
use Statamic\Events\TaxonomySaving;
use Statamic\Facades;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Support\Arr;
use Statamic\Taxonomies\Taxonomy;
use Tests\PreventSavingStacheItemsToDisk;
use Tests\TestCase;

class TaxonomyTest extends TestCase
{
    use PreventSavingStacheItemsToDisk;

    /** @test */
    public function it_stores_cascading_data_in_a_collection()
    {
        $taxonomy = new Taxonomy;
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $taxonomy->cascade());
        $this->assertTrue($taxonomy->cascade()->isEmpty());

        $taxonomy->cascade()->put('foo', 'bar');

        $this->assertTrue($taxonomy->cascade()->has('foo'));
        $this->assertEquals('bar', $taxonomy->cascade()->get('foo'));
    }

    /** @test */
    public function it_sets_all_the_cascade_data_when_passing_an_array()
    {
        $taxonomy = new Taxonomy;

        $return = $taxonomy->cascade($arr = ['foo' => 'bar', 'baz' => 'qux']);
        $this->assertEquals($taxonomy, $return);
        $this->assertEquals($arr, $taxonomy->cascade()->all());

        // test that passing an empty array is not treated as passing null
        $return = $taxonomy->cascade([]);
        $this->assertEquals($taxonomy, $return);
        $this->assertEquals([], $taxonomy->cascade()->all());
    }

    /** @test */
    public function it_gets_values_from_the_cascade_with_fallbacks()
    {
        $taxonomy = new Taxonomy;
        $taxonomy->cascade(['foo' => 'bar']);

        $this->assertEquals('bar', $taxonomy->cascade('foo'));
        $this->assertNull($taxonomy->cascade('baz'));
        $this->assertEquals('qux', $taxonomy->cascade('baz', 'qux'));
    }

    /** @test */
    public function it_gets_term_blueprints()
    {
        $taxonomy = (new Taxonomy)->handle('tags');

        BlueprintRepository::shouldReceive('in')->with('taxonomies/tags')->andReturn(collect([
            'one' => $blueprintOne = (new Blueprint)->setHandle('one'),
            'two' => $blueprintTwo = (new Blueprint)->setHandle('two'),
        ]));

        $blueprints = $taxonomy->termBlueprints();
        $this->assertCount(2, $blueprints);
        $this->assertEveryItemIsInstanceOf(Blueprint::class, $blueprints);
        $this->assertEquals([$blueprintOne, $blueprintTwo], $blueprints->all());

        $this->assertEquals($blueprintOne, $taxonomy->termBlueprint());
        $this->assertEquals($blueprintOne, $taxonomy->termBlueprint('one'));
        $this->assertEquals($blueprintTwo, $taxonomy->termBlueprint('two'));
        $this->assertNull($taxonomy->termBlueprint('three'));
    }

    /** @test */
    public function no_existing_blueprints_will_fall_back_to_a_default_named_after_the_taxonomy()
    {
        $taxonomy = (new Taxonomy)->handle('tags');

        BlueprintRepository::shouldReceive('in')->with('taxonomies/tags')->andReturn(collect());
        BlueprintRepository::shouldReceive('find')->with('default')->andReturn(
            $blueprint = (new Blueprint)
                ->setHandle('thisll_change')
                ->setContents(['title' => 'This will change'])
        );

        $blueprints = $taxonomy->termBlueprints();
        $this->assertCount(1, $blueprints);
        $this->assertEquals([$blueprint], $blueprints->all());

        tap($taxonomy->termBlueprint(), function ($default) use ($blueprint) {
            $this->assertEquals($blueprint, $default);
            $this->assertEquals('tags', $default->handle());
            $this->assertEquals('Tags', $default->title());
        });

        $this->assertEquals($blueprint, $taxonomy->termBlueprint('tags'));
        $this->assertNull($taxonomy->termBlueprint('two'));
    }

    /** @test */
    public function it_gets_the_url()
    {
        $taxonomy = (new Taxonomy)->handle('tags');

        $this->assertEquals('/tags', $taxonomy->uri());
        $this->assertEquals('/tags', $taxonomy->url());
        $this->assertEquals('http://localhost/tags', $taxonomy->absoluteUrl());
    }

    /** @test */
    public function it_gets_the_url_when_the_site_is_using_a_subdirectory()
    {
        $config = config('statamic.sites');
        Arr::set($config, 'sites.en.url', '/subdirectory/');
        Site::setConfig($config);

        $taxonomy = (new Taxonomy)->handle('tags');

        $this->assertEquals('/tags', $taxonomy->uri());
        $this->assertEquals('/subdirectory/tags', $taxonomy->url());
        $this->assertEquals('http://localhost/subdirectory/tags', $taxonomy->absoluteUrl());
    }

    /** @test */
    public function it_gets_the_url_with_a_collection()
    {
        $entry = $this->mock(EntryContract::class);
        $entry->shouldReceive('in')->andReturnSelf();
        $entry->shouldReceive('uri')->andReturn('/blog');
        Entry::shouldReceive('find')->with('blog-page')->andReturn($entry);

        $collection = tap(Collection::make('blog')->mount('blog-page'))->save();

        $taxonomy = (new Taxonomy)->handle('tags')->collection($collection);

        $this->assertEquals('/blog/tags', $taxonomy->uri());
        $this->assertEquals('/blog/tags', $taxonomy->url());
        $this->assertEquals('http://localhost/blog/tags', $taxonomy->absoluteUrl());
    }

    /** @test */
    public function it_gets_evaluated_augmented_value_using_magic_property()
    {
        $taxonomy = (new Taxonomy)->handle('tags');

        $taxonomy
            ->toAugmentedCollection()
            ->each(fn ($value, $key) => $this->assertEquals($value->value(), $taxonomy->{$key}))
            ->each(fn ($value, $key) => $this->assertEquals($value->value(), $taxonomy[$key]));
    }

    /** @test */
    public function it_is_arrayable()
    {
        $taxonomy = (new Taxonomy)->handle('tags');

        $this->assertInstanceOf(Arrayable::class, $taxonomy);

        collect($taxonomy->toArray())
            ->each(fn ($value, $key) => $this->assertEquals($value, $taxonomy->{$key}))
            ->each(fn ($value, $key) => $this->assertEquals($value, $taxonomy[$key]));
    }

    /**
     * @test
     * @dataProvider additionalPreviewTargetProvider
     */
    public function it_gets_and_sets_preview_targets($throughFacade)
    {
        $taxonomy = (new Taxonomy)->handle('tags');

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $taxonomy->previewTargets());
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $taxonomy->basePreviewTargets());
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $taxonomy->additionalPreviewTargets());

        $this->assertEquals([
            ['label' => 'Term', 'format' => '{permalink}'],
        ], $taxonomy->basePreviewTargets()->all());

        $return = $taxonomy->previewTargets([
            ['label' => 'Foo', 'format' => '{foo}'],
            ['label' => 'Bar', 'format' => '{bar}'],
        ]);

        $this->assertSame($taxonomy, $return);

        $this->assertEquals([
            ['label' => 'Foo', 'format' => '{foo}'],
            ['label' => 'Bar', 'format' => '{bar}'],
        ], $taxonomy->previewTargets()->all());

        $this->assertEquals([
            ['label' => 'Foo', 'format' => '{foo}'],
            ['label' => 'Bar', 'format' => '{bar}'],
        ], $taxonomy->basePreviewTargets()->all());

        $this->assertEquals([], $taxonomy->additionalPreviewTargets()->all());

        $extra = [
            ['label' => 'Baz', 'format' => '{baz}'],
            ['label' => 'Qux', 'format' => '{qux}'],
        ];

        if ($throughFacade) {
            \Statamic\Facades\Taxonomy::addPreviewTargets('tags', $extra);
        } else {
            $taxonomy->addPreviewTargets($extra);
        }

        $this->assertEquals([
            ['label' => 'Foo', 'format' => '{foo}'],
            ['label' => 'Bar', 'format' => '{bar}'],
            ['label' => 'Baz', 'format' => '{baz}'],
            ['label' => 'Qux', 'format' => '{qux}'],
        ], $taxonomy->previewTargets()->all());

        $this->assertEquals([
            ['label' => 'Foo', 'format' => '{foo}'],
            ['label' => 'Bar', 'format' => '{bar}'],
        ], $taxonomy->basePreviewTargets()->all());

        $this->assertEquals([
            ['label' => 'Baz', 'format' => '{baz}'],
            ['label' => 'Qux', 'format' => '{qux}'],
        ], $taxonomy->additionalPreviewTargets()->all());
    }

    /** @test */
    public function it_trucates_terms()
    {
        $taxonomy = tap(Facades\Taxonomy::make('tags'))->save();
        Facades\Term::make()->taxonomy('tags')->slug('one')->data([])->save();
        Facades\Term::make()->taxonomy('tags')->slug('two')->data([])->save();
        Facades\Term::make()->taxonomy('tags')->slug('three')->data([])->save();

        $this->assertCount(3, $taxonomy->queryTerms()->get());

        $taxonomy->truncate();

        $this->assertCount(0, $taxonomy->queryTerms()->get());
    }

    /** @test */
    public function it_saves_through_the_api()
    {
        Event::fake();

        $taxonomy = (new Taxonomy)->handle('tags');

        $return = $taxonomy->save();

        $this->assertTrue($return);

        Event::assertDispatched(TaxonomySaving::class, function ($event) use ($taxonomy) {
            return $event->taxonomy = $taxonomy;
        });

        Event::assertDispatched(TaxonomyCreated::class, function ($event) use ($taxonomy) {
            return $event->taxonomy = $taxonomy;
        });

        Event::assertDispatched(TaxonomySaved::class, function ($event) use ($taxonomy) {
            return $event->taxonomy = $taxonomy;
        });
    }

    /** @test */
    public function it_dispatches_taxonomy_created_only_once()
    {
        Event::fake();

        $taxonomy = (new Taxonomy)->handle('tags');

        Facades\Taxonomy::shouldReceive('save')->with($taxonomy);
        Facades\Taxonomy::shouldReceive('find')->with($taxonomy->id())->times(3)->andReturn(null, $taxonomy, $taxonomy);

        $taxonomy->save();
        $taxonomy->save();
        $taxonomy->save();

        Event::assertDispatched(TaxonomySaved::class, 3);
        Event::assertDispatched(TaxonomyCreated::class, 1);
    }

    /** @test */
    public function it_saves_quietly()
    {
        Event::fake();

        $taxonomy = (new Taxonomy)->handle('tags');

        $return = $taxonomy->saveQuietly();

        $this->assertTrue($return);

        Event::assertNotDispatched(TaxonomySaving::class);
        Event::assertNotDispatched(TaxonomySaved::class);
        Event::assertNotDispatched(TaxonomyCreated::class);
    }

    /** @test */
    public function if_saving_event_returns_false_the_taxonomy_doesnt_save()
    {
        Event::fake([TaxonomySaved::class]);

        Event::listen(TaxonomySaving::class, function () {
            return false;
        });

        $taxonomy = (new Taxonomy)->handle('tags');

        $return = $taxonomy->save();

        $this->assertFalse($return);

        Event::assertNotDispatched(TaxonomySaved::class);
    }

    public function additionalPreviewTargetProvider()
    {
        return [
            'through object' => [false],
            'through facade' => [true],
        ];
    }
}
