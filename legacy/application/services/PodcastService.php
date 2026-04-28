<?php

class InvalidPodcastException extends Exception {}

class PodcastNotFoundException extends Exception {}

class Application_Service_PodcastService
{
    // These fields should never be modified with POST/PUT data
    private static $privateFields = [
        'id',
        'url',
        'type',
        'owner',
    ];

    /** @var string[] Fields kept in cc_prefs or computed for the UI; never written to Podcast via Propel */
    private static $stationPodcastUiOnlyFields = [
        'apple_readiness',
        'artwork_public_url',
        'has_dedicated_podcast_artwork',
        'apple_category_primary',
        'apple_category_subcategory',
        'podcast_apple_show_type',
        'podcast_apple_owner_name',
        'podcast_apple_owner_email',
    ];

    /**
     * Returns parsed rss feed, or false if the given URL cannot be downloaded.
     *
     * @param string $feedUrl String containing the podcast feed URL
     *
     * @return mixed
     */
    public static function getPodcastFeed($feedUrl)
    {
        try {
            $feed = new SimplePie();
            $feed->set_feed_url($feedUrl);
            $feed->enable_cache(false);
            $feed->init();

            return $feed;
        } catch (Exception $e) {
            return false;
        }
    }

    /** Creates a Podcast object from the given podcast URL.
     *  This is used by our Podcast REST API.
     *
     * @param string $feedUrl Podcast RSS Feed Url
     *
     * @return array Podcast Array with a full list of episodes
     *
     * @throws Exception
     * @throws InvalidPodcastException
     */
    public static function createFromFeedUrl($feedUrl)
    {
        // TODO: why is this so slow?
        $rss = self::getPodcastFeed($feedUrl);
        if (!$rss) {
            throw new InvalidPodcastException();
        }
        $rssErr = $rss->error();
        if (!empty($rssErr)) {
            throw new InvalidPodcastException($rssErr);
        }

        // Ensure we are only creating Podcast with the given URL, and excluding
        // any extra data fields that may have been POSTED
        $podcastArray = [];
        $podcastArray['url'] = $feedUrl;

        $podcastArray['title'] = htmlspecialchars($rss->get_title() ?? '');
        $podcastArray['description'] = htmlspecialchars($rss->get_description() ?? '');
        $podcastArray['link'] = htmlspecialchars($rss->get_link() ?? '');
        $podcastArray['language'] = htmlspecialchars($rss->get_language() ?? '');
        $podcastArray['copyright'] = htmlspecialchars($rss->get_copyright() ?? '');

        $author = $rss->get_author();
        $name = empty($author) ? '' : $author->get_name();
        $podcastArray['creator'] = htmlspecialchars($name ?? '');

        $categories = [];
        if (is_array($rss->get_categories())) {
            foreach ($rss->get_categories() as $category) {
                array_push($categories, $category->get_scheme() . ':' . $category->get_term());
            }
        }
        $podcastArray['category'] = htmlspecialchars(implode('', $categories));

        // TODO: put in constants
        $itunesChannel = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

        $itunesSubtitle = $rss->get_channel_tags($itunesChannel, 'subtitle');
        $podcastArray['itunes_subtitle'] = isset($itunesSubtitle[0]['data']) ? $itunesSubtitle[0]['data'] : '';

        $itunesCategory = $rss->get_channel_tags($itunesChannel, 'category');
        $categoryArray = [];
        if (is_array($itunesCategory)) {
            foreach ($itunesCategory as $c => $data) {
                foreach ($data['attribs'] as $attrib) {
                    array_push($categoryArray, $attrib['text']);
                }
            }
        }
        $podcastArray['itunes_category'] = implode(',', $categoryArray);

        $itunesAuthor = $rss->get_channel_tags($itunesChannel, 'author');
        $podcastArray['itunes_author'] = isset($itunesAuthor[0]['data']) ? $itunesAuthor[0]['data'] : '';

        $itunesSummary = $rss->get_channel_tags($itunesChannel, 'summary');
        $podcastArray['itunes_summary'] = isset($itunesSummary[0]['data']) ? $itunesSummary[0]['data'] : '';

        $itunesKeywords = $rss->get_channel_tags($itunesChannel, 'keywords');
        $podcastArray['itunes_keywords'] = isset($itunesKeywords[0]['data']) ? $itunesKeywords[0]['data'] : '';

        $itunesExplicit = $rss->get_channel_tags($itunesChannel, 'explicit');
        $podcastArray['itunes_explicit'] = isset($itunesExplicit[0]['data']) ? $itunesExplicit[0]['data'] : '';

        self::validatePodcastMetadata($podcastArray);

        try {
            // Base class
            $podcast = new Podcast();
            $podcast->fromArray($podcastArray, BasePeer::TYPE_FIELDNAME);
            $podcast->setDbOwner(self::getOwnerId());
            $podcast->save();

            $importedPodcast = new ImportedPodcast();
            $importedPodcast->fromArray($podcastArray, BasePeer::TYPE_FIELDNAME);
            $importedPodcast->setPodcast($podcast);
            $importedPodcast->setDbAutoIngest(true);
            $importedPodcast->save();

            // if the autosmartblock and album override are enabled then create a smartblock and playlist matching this podcast via the album name
            if (Application_Model_Preference::GetPodcastAutoSmartblock() && Application_Model_Preference::GetPodcastAlbumOverride()) {
                self::createPodcastSmartblockAndPlaylist($podcast);
            }

            return $podcast->toArray(BasePeer::TYPE_FIELDNAME);
        } catch (Exception $e) {
            $podcast->delete();

            throw $e;
        }
    }

    /**
     * @param       $title   passed in directly from web UI input
     *                      This will automatically create a smartblock and playlist for this podcast
     * @param mixed $podcast
     */
    public static function createPodcastSmartblockAndPlaylist($podcast, $title = null)
    {
        if (is_array($podcast)) {
            $newpodcast = new Podcast();
            $newpodcast->fromArray($podcast, BasePeer::TYPE_FIELDNAME);
            $podcast = $newpodcast;
        }
        if ($title == null) {
            $title = $podcast->getDbTitle();
        }
        // Base class
        $newBl = new Application_Model_Block();
        $newBl->setCreator(Application_Model_User::getCurrentUser()->getId());
        $newBl->setName($title);
        $newBl->setDescription(_('Auto-generated smartblock for podcast'));
        $newBl->saveType('dynamic');
        // limit the smartblock to 1 item
        $row = new CcBlockcriteria();
        $row->setDbCriteria('limit');
        $row->setDbModifier('items');
        $row->setDbValue(1);
        $row->setDbBlockId($newBl->getId());
        $row->save();

        // sort so that it is the newest item
        $row = new CcBlockcriteria();
        $row->setDbCriteria('sort');
        $row->setDbModifier('N/A');
        $row->setDbValue('newest');
        $row->setDbBlockId($newBl->getId());
        $row->save();

        // match the track by ensuring the album title matches the podcast
        $row = new CcBlockcriteria();
        $row->setDbCriteria('album_title');
        $row->setDbModifier('is');
        $row->setDbValue($title);
        $row->setDbBlockId($newBl->getId());
        $row->save();

        $newPl = new Application_Model_Playlist();
        $newPl->setName($title);
        $newPl->setCreator(Application_Model_User::getCurrentUser()->getId());
        $row = new CcPlaylistcontents();
        $row->setDbBlockId($newBl->getId());
        $row->setDbPlaylistId($newPl->getId());
        $row->setDbType(2);
        $row->save();
    }

    public static function createStationPodcast()
    {
        $podcast = new Podcast();
        $podcast->setDbUrl(Config::getPublicUrl() . 'feeds/station-rss');

        $title = Application_Model_Preference::GetStationName();
        $title = empty($title) ? "My Station's Podcast" : $title;
        $podcast->setDbTitle($title);

        $podcast->setDbDescription(Application_Model_Preference::GetStationDescription());
        $podcast->setDbLink(Config::getPublicUrl());
        $podcast->setDbLanguage(explode('_', Application_Model_Preference::GetLocale())[0]);
        $podcast->setDbCreator(Application_Model_Preference::GetStationName());
        $podcast->setDbOwner(self::getOwnerId());
        $podcast->save();

        $stationPodcast = new StationPodcast();
        $stationPodcast->setPodcast($podcast);
        $stationPodcast->save();

        Application_Model_Preference::setStationPodcastId($podcast->getDbId());
        // Set the download key when we create the station podcast
        // The value is randomly generated in the setter
        Application_Model_Preference::setStationPodcastDownloadKey();

        return $podcast->getDbId();
    }

    // TODO move this somewhere where it makes sense
    private static function getOwnerId()
    {
        try {
            if (Zend_Auth::getInstance()->hasIdentity()) {
                $service_user = new Application_Service_UserService();

                return $service_user->getCurrentUser()->getDbId();
            }
            $defaultOwner = CcSubjsQuery::create()
                ->filterByDbType('A')
                ->orderByDbId()
                ->findOne();
            if (!$defaultOwner) {
                // what to do if there is no admin user?
                // should we handle this case?
                return null;
            }

            return $defaultOwner->getDbId();
        } catch (Exception $e) {
            Logging::info($e->getMessage());
        }
    }

    /**
     * Trims the podcast metadata to fit the table's column max size.
     *
     * @param PodcastArray &$podcastArray
     */
    private static function validatePodcastMetadata(&$podcastArray)
    {
        $podcastTable = PodcastPeer::getTableMap();

        foreach ($podcastArray as $key => &$value) {
            try {
                // Make sure column exists in table
                $columnMaxSize = $podcastTable->getColumn($key)->getSize();
            } catch (PropelException $e) {
                continue;
            }

            if (strlen($value) > $columnMaxSize) {
                $value = substr($value, 0, $podcastTable->getColumn($key)->getSize());
            }
        }
    }

    /**
     * Fetches a Podcast's rss feed and returns all its episodes with
     * the Podcast object.
     *
     * @param mixed $podcastId
     *
     * @return array - Podcast Array with a full list of episodes
     *
     * @throws PodcastNotFoundException
     * @throws InvalidPodcastException
     */
    public static function getPodcastById($podcastId)
    {
        $podcast = PodcastQuery::create()->findPk($podcastId);
        if (!$podcast) {
            throw new PodcastNotFoundException();
        }

        $podcast = $podcast->toArray(BasePeer::TYPE_FIELDNAME);
        $podcast['itunes_explicit'] = ($podcast['itunes_explicit'] == 'yes') ? true : false;

        if ((string) $podcastId === (string) Application_Model_Preference::getStationPodcastId()) {
            self::attachStationPodcastEditorFields($podcast);
        }

        return $podcast;
    }

    /**
     * Merges station-only Apple/editor fields for the legacy UI and REST payloads.
     *
     * @param array &$podcast fieldname keyed podcast row array
     */
    private static function attachStationPodcastEditorFields(&$podcast)
    {
        $primPref = trim(Application_Model_Preference::getPodcastAppleCategoryPrimary());
        $subPref = trim(Application_Model_Preference::getPodcastAppleCategorySubcategory());
        if ($primPref !== '' || $subPref !== '') {
            $podcast['apple_category_primary'] = $primPref;
            $podcast['apple_category_subcategory'] = $subPref;
        } else {
            $raw = trim($podcast['itunes_category'] ?? '');
            if ($raw !== '') {
                if (strpos($raw, '|') !== false) {
                    $parts = array_pad(explode('|', $raw, 2), 2, '');
                    $podcast['apple_category_primary'] = trim($parts[0]);
                    $podcast['apple_category_subcategory'] = trim((string) $parts[1]);
                } else {
                    $comma = explode(',', $raw);
                    $podcast['apple_category_primary'] = trim($comma[0]);
                    $podcast['apple_category_subcategory'] = '';
                }
            } else {
                $podcast['apple_category_primary'] = '';
                $podcast['apple_category_subcategory'] = '';
            }
        }

        $podcast['podcast_apple_owner_name'] = Application_Model_Preference::getPodcastAppleOwnerName();
        $podcast['podcast_apple_owner_email'] = Application_Model_Preference::getPodcastAppleOwnerEmail();
        $podcast['podcast_apple_show_type'] = Application_Model_Preference::getPodcastAppleShowType();

        $podcast['has_dedicated_podcast_artwork'] = Application_Model_Preference::getPodcastAppleArtworkDecoded() !== '';
        $podcast['artwork_public_url'] = rtrim(Config::getPublicUrl(), '/') . '/api/station-podcast-artwork';

        $diag = Application_Service_PodcastFeedValidationService::getStationReadiness();
        $podcast['apple_readiness'] = [
            'errors' => $diag['errors'],
            'warnings' => $diag['warnings'],
        ];
    }

    /**
     * Subset of station podcast fields returned after artwork POST/remove.
     *
     * @return array{apple_readiness: array, has_dedicated_podcast_artwork: bool, artwork_public_url: string}
     */
    public static function getPartialStationPodcastAppleUiPayload()
    {
        $stationPodcastId = (int) Application_Model_Preference::getStationPodcastId();
        $full = self::getPodcastById($stationPodcastId);

        return [
            'apple_readiness' => $full['apple_readiness'],
            'has_dedicated_podcast_artwork' => $full['has_dedicated_podcast_artwork'],
            'artwork_public_url' => $full['artwork_public_url'],
        ];
    }

    /**
     * @param array $podcastData reference to PUT body podcast object
     */
    private static function persistStationAppleFieldsAndStrip(array &$podcastData)
    {
        $pairs = [
            ['apple_category_primary', 'setPodcastAppleCategoryPrimary'],
            ['apple_category_subcategory', 'setPodcastAppleCategorySubcategory'],
            ['podcast_apple_show_type', 'setPodcastAppleShowType'],
            ['podcast_apple_owner_name', 'setPodcastAppleOwnerName'],
            ['podcast_apple_owner_email', 'setPodcastAppleOwnerEmail'],
        ];
        foreach ($pairs as $pair) {
            [$key, $setter] = $pair;
            if (array_key_exists($key, $podcastData)) {
                call_user_func(['Application_Model_Preference', $setter], $podcastData[$key]);
            }
        }

        $prim = trim(Application_Model_Preference::getPodcastAppleCategoryPrimary());
        $sub = trim(Application_Model_Preference::getPodcastAppleCategorySubcategory());
        $podcastData['itunes_category'] = $prim . ($sub !== '' ? '|' . $sub : '');

        foreach (self::$stationPodcastUiOnlyFields as $k) {
            unset($podcastData[$k]);
        }
    }

    /**
     * Deletes a Podcast and its podcast episodes.
     *
     * @param mixed $podcastId
     *
     * @throws Exception
     * @throws PodcastNotFoundException
     */
    public static function deletePodcastById($podcastId)
    {
        $podcast = PodcastQuery::create()->findPk($podcastId);
        if ($podcast) {
            $podcast->delete();

            // FIXME: I don't think we should be able to delete the station podcast...
            if ($podcastId == Application_Model_Preference::getStationPodcastId()) {
                Application_Model_Preference::setStationPodcastId(null);
            }
        } else {
            throw new PodcastNotFoundException();
        }
    }

    /**
     * Build a response with podcast data and embedded HTML to load on the frontend.
     *
     * @param int                 $podcastId ID of the podcast to build a response for
     * @param Zend_View_Interface $view      Zend view object to render the response HTML
     *
     * @return array the response array containing the podcast data and editor HTML
     *
     * @throws PodcastNotFoundException
     */
    public static function buildPodcastEditorResponse($podcastId, $view)
    {
        // Check the StationPodcast table rather than checking
        // the station podcast ID key in preferences for extensibility
        $podcast = StationPodcastQuery::create()->findOneByDbPodcastId($podcastId);
        $path = $podcast ? 'podcast/station.phtml' : 'podcast/podcast.phtml';
        $podcast = Application_Service_PodcastService::getPodcastById($podcastId);

        return [
            'podcast' => json_encode($podcast),
            'html' => $view->render($path),
        ];
    }

    /**
     * Updates a Podcast object with the given metadata.
     *
     * @param mixed $podcastId
     * @param mixed $data
     *
     * @return array
     *
     * @throws Exception
     * @throws PodcastNotFoundException
     */
    public static function updatePodcastFromArray($podcastId, $data)
    {
        $podcast = PodcastQuery::create()->findPk($podcastId);
        if (!$podcast) {
            throw new PodcastNotFoundException();
        }

        self::removePrivateFields($data['podcast']);
        if ((string) $podcastId === (string) Application_Model_Preference::getStationPodcastId()) {
            self::persistStationAppleFieldsAndStrip($data['podcast']);
        }
        self::validatePodcastMetadata($data['podcast']);
        if (array_key_exists('auto_ingest', $data['podcast'])) {
            self::_updateAutoIngestTimestamp($podcast, $data);
        }

        $data['podcast']['itunes_explicit'] = $data['podcast']['itunes_explicit'] ? 'yes' : 'clean';
        $podcast->fromArray($data['podcast'], BasePeer::TYPE_FIELDNAME);
        $podcast->save();

        if ((string) $podcastId === (string) Application_Model_Preference::getStationPodcastId()) {
            return self::getPodcastById((int) $podcastId);
        }

        return $podcast->toArray(BasePeer::TYPE_FIELDNAME);
    }

    /**
     * Update the automatic ingestion timestamp for the given Podcast.
     *
     * @param Podcast $podcast Podcast object to update
     * @param array   $data    Podcast update data array
     */
    private static function _updateAutoIngestTimestamp($podcast, $data)
    {
        // Get podcast data with lazy loaded columns since we can't directly call getDbAutoIngest()
        $currData = $podcast->toArray(BasePeer::TYPE_FIELDNAME, true);
        // Add an auto-ingest timestamp when turning auto-ingest on
        if ($data['podcast']['auto_ingest'] == 1 && $currData['auto_ingest'] != 1) {
            $data['podcast']['auto_ingest_timestamp'] = gmdate('r');
        }
    }

    private static function removePrivateFields(&$data)
    {
        foreach (self::$privateFields as $key) {
            unset($data[$key]);
        }
    }

    private static function appendRssTextElement(\DOMDocument $doc, \DOMElement $parent, string $tag, ?string $value)
    {
        if ($value === null || $value === '') {
            return;
        }
        $el = $doc->createElement($tag);
        $el->appendChild($doc->createTextNode($value));
        $parent->appendChild($el);
    }

    private static function appendItunesNsElement(\DOMDocument $doc, \DOMElement $parent, string $localName, ?string $value)
    {
        if ($value === null || $value === '') {
            return;
        }
        $el = $doc->createElementNS(ITUNES_XML_NAMESPACE_URL, 'itunes:' . $localName);
        $el->appendChild($doc->createTextNode($value));
        $parent->appendChild($el);
    }

    private static function stationRssExplicitString($explicitDbField)
    {
        return trim((string) $explicitDbField) === 'yes' ? 'yes' : 'no';
    }

    /**
     * @return array{primary: string, sub: string}
     */
    private static function resolvedAppleCategoryStringsFromPodcastRow(Podcast $podcast)
    {
        $primary = trim(Application_Model_Preference::getPodcastAppleCategoryPrimary());
        $sub = trim(Application_Model_Preference::getPodcastAppleCategorySubcategory());
        if ($primary !== '' || $sub !== '') {
            return ['primary' => $primary, 'sub' => $sub];
        }
        $raw = trim((string) $podcast->getDbItunesCategory());
        if ($raw === '') {
            return ['primary' => '', 'sub' => ''];
        }
        if (strpos($raw, '|') !== false) {
            $parts = array_pad(explode('|', $raw, 2), 2, '');

            return ['primary' => trim($parts[0]), 'sub' => trim((string) $parts[1])];
        }
        $comma = explode(',', $raw);

        return ['primary' => trim($comma[0]), 'sub' => ''];
    }

    private static function appendItunesCategoryElements(\DOMDocument $doc, \DOMElement $channel, $primary, $sub)
    {
        if ($primary === '') {
            return;
        }
        $ns = ITUNES_XML_NAMESPACE_URL;
        if ($sub !== '') {
            $outer = $doc->createElementNS($ns, 'itunes:category');
            $outer->setAttribute('text', $primary);
            $inner = $doc->createElementNS($ns, 'itunes:category');
            $inner->setAttribute('text', $sub);
            $outer->appendChild($inner);
            $channel->appendChild($outer);
        } else {
            $c = $doc->createElementNS($ns, 'itunes:category');
            $c->setAttribute('text', $primary);
            $channel->appendChild($c);
        }
    }

    public static function createStationRssFeed()
    {
        $stationPodcastId = Application_Model_Preference::getStationPodcastId();

        try {
            $podcast = PodcastQuery::create()->findPk($stationPodcastId);
            if (!$podcast) {
                throw new PodcastNotFoundException();
            }

            $public = rtrim(Config::getPublicUrl(), '/');
            $selfUrl = (string) $podcast->getDbUrl();
            if ($selfUrl === '') {
                $selfUrl = $public . '/feeds/station-rss';
            }
            $artHref = $public . '/api/station-podcast-artwork';

            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->formatOutput = true;

            $rss = $doc->createElement('rss');
            $rss->setAttribute('version', '2.0');
            $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:itunes', ITUNES_XML_NAMESPACE_URL);
            $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
            $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
            $doc->appendChild($rss);

            $channel = $doc->createElement('channel');
            $rss->appendChild($channel);

            self::appendRssTextElement($doc, $channel, 'title', $podcast->getDbTitle());
            self::appendRssTextElement($doc, $channel, 'link', $podcast->getDbLink());
            self::appendRssTextElement($doc, $channel, 'description', $podcast->getDbDescription());
            self::appendRssTextElement($doc, $channel, 'language', $podcast->getDbLanguage());
            self::appendRssTextElement($doc, $channel, 'copyright', $podcast->getDbCopyright());

            $atom = $doc->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
            $atom->setAttribute('href', $selfUrl);
            $atom->setAttribute('rel', 'self');
            $atom->setAttribute('type', 'application/rss+xml');
            $channel->appendChild($atom);

            $image = $doc->createElement('image');
            self::appendRssTextElement($doc, $image, 'title', $podcast->getDbTitle());
            self::appendRssTextElement($doc, $image, 'url', $artHref);
            self::appendRssTextElement($doc, $image, 'link', Config::getPublicUrl());
            $channel->appendChild($image);

            $author = $podcast->getDbItunesAuthor();
            if ($author === null || trim($author) === '') {
                $author = Application_Model_Preference::GetStationName();
            }
            self::appendItunesNsElement($doc, $channel, 'author', $author);

            self::appendItunesNsElement($doc, $channel, 'summary', $podcast->getDbItunesSummary());
            self::appendItunesNsElement($doc, $channel, 'subtitle', $podcast->getDbItunesSubtitle());
            self::appendItunesNsElement($doc, $channel, 'keywords', $podcast->getDbItunesKeywords());
            self::appendItunesNsElement($doc, $channel, 'explicit', self::stationRssExplicitString($podcast->getDbItunesExplicit()));
            self::appendItunesNsElement($doc, $channel, 'type', Application_Model_Preference::getPodcastAppleShowType());

            $ownerNamePref = trim(Application_Model_Preference::getPodcastAppleOwnerName());
            $ownerName = $ownerNamePref !== '' ? $ownerNamePref : Application_Model_Preference::GetStationName();
            $ownerEmailPref = trim(Application_Model_Preference::getPodcastAppleOwnerEmail());
            $ownerEmail = $ownerEmailPref !== '' ? $ownerEmailPref : (string) Application_Model_Preference::GetEmail();

            $owner = $doc->createElementNS(ITUNES_XML_NAMESPACE_URL, 'itunes:owner');
            self::appendItunesNsElement($doc, $owner, 'name', $ownerName);
            self::appendItunesNsElement($doc, $owner, 'email', $ownerEmail);
            $channel->appendChild($owner);

            $ti = $doc->createElementNS(ITUNES_XML_NAMESPACE_URL, 'itunes:image');
            $ti->setAttribute('href', $artHref);
            $channel->appendChild($ti);

            $cats = self::resolvedAppleCategoryStringsFromPodcastRow($podcast);
            self::appendItunesCategoryElements($doc, $channel, $cats['primary'], $cats['sub']);

            $chExplicit = self::stationRssExplicitString($podcast->getDbItunesExplicit());

            $episodes = PodcastEpisodesQuery::create()
                ->filterByDbPodcastId($stationPodcastId)
                ->orderByDbPublicationDate(\Criteria::DESC)
                ->find();

            foreach ($episodes as $episode) {
                /** @var PodcastEpisodes $episode */
                $publishedFile = CcFilesQuery::create()->findPk($episode->getDbFileId());
                if (!$publishedFile) {
                    continue;
                }

                $item = $doc->createElement('item');

                self::appendRssTextElement($doc, $item, 'title', $publishedFile->getDbTrackTitle());
                self::appendRssTextElement($doc, $item, 'pubDate', gmdate(DATE_RFC2822, strtotime((string) $episode->getDbPublicationDate())));
                foreach (array_filter([$cats['primary'], $cats['sub']]) as $c) {
                    self::appendRssTextElement($doc, $item, 'category', $c);
                }

                $guidEl = $doc->createElement('guid');
                $guidEl->appendChild($doc->createTextNode((string) $episode->getDbEpisodeGuid()));
                $guidEl->setAttribute('isPermaLink', 'false');
                $item->appendChild($guidEl);

                $desc = trim((string) ($publishedFile->getDbDescription() ?? ''));
                if ($desc === '') {
                    $desc = (string) ($publishedFile->getDbTrackTitle() ?? '');
                }
                self::appendRssTextElement($doc, $item, 'description', $desc);

                $enclosure = $doc->createElement('enclosure');
                $enclosure->setAttribute('url', $episode->getDbDownloadUrl());
                $enclosure->setAttribute('length', (string) $publishedFile->getDbFilesize());
                $enclosure->setAttribute('type', $publishedFile->getDbMime());
                $item->appendChild($enclosure);

                self::appendItunesNsElement($doc, $item, 'subtitle', $desc);
                self::appendItunesNsElement($doc, $item, 'summary', $desc);
                self::appendItunesNsElement($doc, $item, 'author', $publishedFile->getDbArtistName());
                self::appendItunesNsElement($doc, $item, 'explicit', $chExplicit);
                self::appendItunesNsElement($doc, $item, 'episodeType', 'full');
                self::appendItunesNsElement($doc, $item, 'duration', explode('.', (string) $publishedFile->getDbLength())[0]);

                $channel->appendChild($item);
            }

            return $doc->saveXML();
        } catch (Exception $e) {
            Logging::error('createStationRssFeed: ' . $e->getMessage());

            return false;
        }
    }
}
