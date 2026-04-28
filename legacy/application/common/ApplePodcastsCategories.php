<?php

/**
 * Apple Podcasts category hierarchy (parent => subcategory labels).
 * Values must match Apple Podcasts Connect / RSS expectations.
 */
class Application_Common_ApplePodcastsCategories
{
    /**
     * @var array<string, list<string>>
     */
    private static $hierarchy = [
        'Arts' => [
            'Books', 'Design', 'Fashion & Beauty', 'Food', 'Performing Arts', 'Visual Arts',
        ],
        'Business' => [
            'Careers', 'Entrepreneurship', 'Investing', 'Management', 'Marketing', 'Non-Profit',
        ],
        'Comedy' => [
            'Comedy Interviews', 'Improv', 'Stand-Up',
        ],
        'Education' => [
            'Courses', 'How To', 'Language Learning', 'Self-Improvement',
        ],
        'Fiction' => [
            'Comedy Fiction', 'Drama', 'Science Fiction',
        ],
        'Government' => [],
        'History' => [],
        'Health & Fitness' => [
            'Alternative Health', 'Fitness', 'Medicine', 'Mental Health', 'Nutrition', 'Sexuality',
        ],
        'Kids & Family' => [
            'Education for Kids', 'Parenting', 'Pets & Animals', 'Stories for Kids',
        ],
        'Leisure' => [
            'Animation & Manga', 'Automotive', 'Aviation', 'Crafts', 'Games', 'Hobbies',
            'Home & Garden', 'Video Games',
        ],
        'Music' => [
            'Music Commentary', 'Music History', 'Music Interviews',
        ],
        'News' => [
            'Business News', 'Daily News', 'Entertainment News', 'News Commentary', 'Politics',
            'Sports News', 'Tech News',
        ],
        'Religion & Spirituality' => [
            'Buddhism', 'Christianity', 'Hinduism', 'Islam', 'Judaism', 'Religion', 'Spirituality',
        ],
        'Science' => [
            'Astronomy', 'Chemistry', 'Earth Sciences', 'Life Sciences', 'Mathematics',
            'Natural Sciences', 'Nature', 'Physics', 'Social Sciences',
        ],
        'Society & Culture' => [
            'Documentary', 'Personal Journals', 'Philosophy', 'Places & Travel', 'Relationships',
        ],
        'Sports' => [
            'Baseball', 'Basketball', 'Cricket', 'Fantasy Sports', 'American Football', 'Golf',
            'Hockey', 'Rugby', 'Running', 'Soccer', 'Swimming', 'Tennis', 'Volleyball',
            'Wilderness', 'Wrestling',
        ],
        'Technology' => [],
        'True Crime' => [],
        'TV & Film' => [
            'After Shows', 'Film History', 'Film Interviews', 'Film Reviews', 'TV Reviews',
        ],
    ];

    /** @return array<string, list<string>> */
    public static function getHierarchy()
    {
        return self::$hierarchy;
    }

    /**
     * @return list<string>
     */
    public static function getPrimaryOptions()
    {
        return array_keys(self::$hierarchy);
    }

    /**
     * @return list<string>
     */
    public static function getSubcategoriesFor(?string $primary)
    {
        if ($primary === null || $primary === '' || !isset(self::$hierarchy[$primary])) {
            return [];
        }

        return self::$hierarchy[$primary];
    }

    public static function isValidPrimary(?string $primary)
    {
        return $primary !== null && $primary !== '' && array_key_exists($primary, self::$hierarchy);
    }

    /**
     * Subcategory optional: empty means parent-only category is valid only when hierarchy allows no subs OR when user clears sub.
     * If hierarchy has subs, empty subcategory is allowed (shown as nested parent-only per Apple docs for leaf primaries).
     */
    public static function isValidPair(?string $primary, ?string $subcategory)
    {
        if (!self::isValidPrimary($primary)) {
            return false;
        }
        $subs = self::$hierarchy[$primary];
        if ($subcategory === null || $subcategory === '') {
            return true;
        }

        return in_array($subcategory, $subs, true);
    }
}
