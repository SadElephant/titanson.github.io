<?php

namespace App;

use Carbon\Carbon;
use DonatelloZa\RakePlus\RakePlus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;
use Symfony\Component\Mime\MimeTypes;

class News extends Model implements Feedable
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'pubDate',
        'timestamp',
        'description',
        'link',
        'media',
        'internalLink',
    ];

    /**
     * @var array
     */
    protected $appends = [
        'id',
        'favicon',
        'domain',
        'image',
        'internalLink',
        'keywords',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'title'       => 'string',
        'pubDate'     => 'datetime',
        'description' => 'string',
        'link'        => 'string',
        'media'       => 'array',
        'domain'      => 'string',
        'favicon'     => 'string',
        'image'       => 'string',
        'keywords'    => 'array',
        'timestamp'   => 'integer',
    ];

    /**
     * @return string
     */
    public function getDomainAttribute(): string
    {
        return parse_url($this->link, PHP_URL_HOST);
    }

    /**
     * @return string
     */
    public function getFaviconAttribute(): string
    {
        return 'https://www.google.com/s2/favicons?domain=' . $this->domain;
    }

    /**
     * @return string|null
     */
    public function getImageAttribute(): ?string
    {
        //$keys = Str::of($this->title)->slug(',');

        //return "https://source.unsplash.com/random/?$keys&order_by=latest";


        if (empty($this->media)) {
            return null;
        }

        $media = collect($this->media)->filter(function (array $info) {
            if (! isset($info['type'], $info['url'])) {
                return false;
            }

            if (! Str::contains($info['type'], 'image')) {
                return false;
            }

            return ! Str::contains($info['url'], config('smi.ignore.covers'));
        })->first();

        return $media['url'] ?? null;
    }

    /**
     * @param $date
     */
    public function setPubDateAttribute($date): void
    {
        $this->attributes['pubDate'] = Carbon::parse($date, 'Europe/Moscow');
        $this->attributes['timestamp'] = $this->attributes['pubDate']->getTimestamp();
    }

    /**
     * @param string $title
     */
    public function setTitleAttribute(string $title): void
    {
        $title = htmlspecialchars_decode($title);

        $this->attributes['title'] = Str::of($title)->stripTags()->trim();
    }

    /**
     * @param string $description
     */
    public function setDescriptionAttribute(?string $description): void
    {
        $description = htmlspecialchars_decode($description);

        $this->attributes['description'] = Str::of($description)->stripTags()->trim();
    }

    /**
     * @return string|null
     */
    public function getIdAttribute(): ?string
    {
        return hash('crc32b', $this->link);
    }

    /**
     * @return string
     */
    public function getInternalLinkAttribute(): string
    {
        return route('news', $this->id);
    }

    /**
     * @return array
     */
    public function getKeywordsAttribute(): array
    {
        $description = Str::before(strip_tags($this->description ?? $this->title), '.');

        return (new Stringable($this->description .'.'. $this->title))
            ->explode(' ')
            ->map(static function (string $word) {
                $word = str_replace(['(', ')', '"', "'", '$', '«', ',', '.'], '', $word);

                if (is_numeric($word) || mb_strlen($word) < 4) {
                    return null;
                }

                return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8') === $word
                    ? $word
                    : null;
            })
            ->filter()
            //->skip(1)
            ->whenEmpty(function () use ($description) {
                $rake = RakePlus::create($description, 'ru_RU', 4);

                $scores = $rake->sortByScore('desc')->scores();

                return collect($scores)->keys();
            })
            ->toArray();
    }

    /**
     * @return FeedItem
     */
    public function toFeedItem(): FeedItem
    {
        $image = $this->image ?? url('/img/cover.jpg');
        $mimeTypes = (new MimeTypes())->getMimeTypes(pathinfo($image, PATHINFO_EXTENSION));


        $summary = Str::before(strip_tags($this->description ?? $this->title), '.');
        $keywords = $this->keywords;
        $keywordsWithHahTag = array_map(function (string $word) {
            return '#' . $word;
        }, $keywords);

        $summary = str_replace($keywords, $keywordsWithHahTag, $summary);


        return FeedItem::create()
            ->id(Str::slug($this->pubDate . '/' . $this->id))
            ->title($this->title)
            ->summary($summary)
            ->enclosure($image)
            ->enclosureType(array_shift($mimeTypes) ?? 'image/unknown')
            ->enclosureLength(0)
            ->updated($this->pubDate)
            ->author($this->domain)
            ->link($this->internalLink);
    }

    /**
     * @return Collection
     */
    public static function getFeedItems(): Collection
    {
        // Не генерировать в ночное время rss фид
        $currentHour = now('Europe/Moscow')->format('H');
        if ($currentHour > 22 || $currentHour < 9) {
            return collect();
        }

        return Source::getSimilarNews()->map(function (Collection $group) {
            return $group->get('main');
        })->take(4)->filter(function (News $news) {
            return $news->pubDate->addHours(6)->isAfter(now());
        });
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string)$this->title;
    }
}
