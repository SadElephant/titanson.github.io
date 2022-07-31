<?php

use App\News;
use App\Source;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

$index = [
    'stories'  => Source::getSimilarNews(),
    'lastNews' => Source::getLastNews()->take(config('smi.news.renderCount')),
    //'tags'     => Source::getMostTags(),
];


//dd(Source::getSimilarNews());

Route::view('/', 'index', $index)->name('index');
Route::get('/sources/{id}/source', function (string $id){
    $group = Source::getSimilarNews()->mapWithKeys(function($items, $key){
        return [md5($key) => $items];
    })->get($id);

   abort_if(empty($group), 404);

    return view('sources', $group->all());
})->name('sources');




Route::view('/list', 'list', $index)->name('list');
Route::view('/group', 'group', $index)->name('group');
Route::view('/policy', 'policy')->name('policy');

Route::view('/404', 'index', $index)->name('404');

Route::get('/news/{id}', function (string $id) {

    // News in story
    $story = Source::getSimilarNews()
        ->filter(static function (Collection $stories) use ($id) {
            return $stories->get('items')->filter(function (News $news) use ($id) {
                return $news->id === $id;
            })->isNotEmpty();
        })->first();

    // Random
    $stories = Source::getSimilarNews()
        ->filter(static function (Collection $stories) use ($story) {
            if ($story === null) {
                return true;
            }

            return $stories->get('main')->id !== $story->get('main')->id;
        })->random(4);

    // Simple news
    $news = Source::getLastNews()
        ->filter(static function (News $news) use ($id) {
            return $news->id === $id;
        })->whenEmpty(function () {
            abort(404);
        })->first();

    $story = $story !== null
        ? $story->put('main', $news)
        : collect([
            'main'  => $news,
            'items' => collect(),
        ]);

    return view('news', [
        'story'    => $story,
        'stories'  => $stories,
        'lastNews' => Source::getLastNews()->take(config('smi.news.renderCount')),
    ]);

})->name('news');


Route::get('/tags/{slug}', function (string $slug) {

    $tag = collect(config('smi.tags'))->where('slug', $slug)->first();

    abort_if($tag === null, 404);

    $news = Source::getLastNews()->filter(function (News $news) use ($tag) {
        return Str::contains($news->title, $tag['contains'])
            || Str::contains($news->description, $tag['contains']);
    });


    return view('tags', [
        'news'     => $news,
        'lastNews' => Source::getLastNews()->take(config('smi.news.renderCount')),
    ]);

})->name('tags');

Route::feeds();
