<?php

require_once APPLICATION_PATH . '/common/ApplePodcastsCategories.php';

class Application_Service_PodcastFeedValidationService
{
    /**
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function getStationReadiness()
    {
        $errors = [];
        $warnings = [];

        $publicBase = rtrim(Config::getPublicUrl(), '/');
        if ($publicBase !== '' && stripos($publicBase, 'https://') !== 0) {
            $warnings[] = _('Public site URL should use HTTPS for Apple Podcasts.');
        }

        $stationPodcastId = Application_Model_Preference::getStationPodcastId();
        $podcast = PodcastQuery::create()->findPk($stationPodcastId);
        if (!$podcast) {
            $errors[] = _('Station podcast record is missing.');

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $feedUrl = $podcast->getDbUrl() ?? '';
        if ($feedUrl !== '' && stripos($feedUrl, 'https://') !== 0) {
            $warnings[] = _('RSS feed URL should use HTTPS.');
        }

        if (Application_Model_Preference::getStationPodcastPrivacy()) {
            $warnings[] = _('Feed privacy is enabled: Apple Podcasts needs a public feed URL (you can still use the token until you go public).');
        }

        $ownerEmailPref = trim(Application_Model_Preference::getPodcastAppleOwnerEmail());
        $email = $ownerEmailPref !== ''
            ? $ownerEmailPref
            : (string) Application_Model_Preference::GetEmail();

        if ($email === '') {
            $warnings[] = _('Apple recommends an owner email in the feed (Apple Podcast Connect / itunes:owner).');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $warnings[] = _('Owner email does not look like a valid address.');
        }

        $prim = trim(Application_Model_Preference::getPodcastAppleCategoryPrimary());
        $sub = trim(Application_Model_Preference::getPodcastAppleCategorySubcategory());
        if ($prim === '') {
            $fallback = trim((string) $podcast->getDbItunesCategory());
            if ($fallback === '') {
                $warnings[] = _('Select an Apple category (recommended for discovery).');
            }
        } else {
            if (!Application_Common_ApplePodcastsCategories::isValidPrimary($prim)) {
                $warnings[] = _('Primary category must match Apple’s approved list.');
            } elseif ($sub !== '') {
                $allowed = Application_Common_ApplePodcastsCategories::getSubcategoriesFor($prim);
                $allowedSubs = empty($allowed) ? [] : $allowed;
                if (!in_array($sub, $allowedSubs, true)) {
                    $warnings[] = _('Subcategory must belong to the chosen primary Apple category.');
                }
            }
        }

        $artDecoded = Application_Model_Preference::getPodcastAppleArtworkDecoded();
        $hasDedicated = $artDecoded !== '';
        if (!$hasDedicated) {
            $warnings[] = _('No dedicated podcast artwork uploaded: the feed falls back to the station logo until you add square JPG/PNG artwork (1400–3000 px).');
        } else {
            $info = @getimagesizefromstring($artDecoded);
            if ($info === false) {
                $warnings[] = _('Dedicated artwork could not be read as an image.');
            } else {
                [$w, $h] = $info;
                if ($w !== $h) {
                    $warnings[] = _('Podcast artwork should be square.');
                }
                if ($w < 1400 || $w > 3000) {
                    $warnings[] = sprintf(_('Apple recommends artwork width between 1400 and 3000 px (got %dx%d).'), (int) $w, (int) $h);
                }
                $itype = isset($info[2]) ? (int) $info[2] : 0;
                if (!in_array($itype, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                    $warnings[] = _('Dedicated artwork must be JPG or PNG.');
                }
            }
        }

        $epRows = PodcastEpisodesQuery::create()
            ->filterByDbPodcastId($stationPodcastId)
            ->find();

        if (count($epRows) < 1) {
            $warnings[] = _('Apple expects at least one episode in the feed.');
        }

        foreach ($epRows as $episode) {
            /** @var PodcastEpisodes $episode */
            $file = CcFilesQuery::create()->findPk($episode->getDbFileId());
            if (!$file) {
                $warnings[] = _('Some published episodes are missing linked media.');
                continue;
            }
            $download = (string) $episode->getDbDownloadUrl();
            if ($download === '') {
                $warnings[] = _('An episode is missing enclosure URL.');
                continue;
            }
            if (stripos($download, 'https://') !== 0) {
                $warnings[] = _('Episode media URLs should use HTTPS.');
            }
            if ((int) $file->getDbFilesize() < 1) {
                $warnings[] = _('An episode enclosure is missing byte length.');
            }
            if (trim((string) $file->getDbMime()) === '') {
                $warnings[] = _('An episode enclosure is missing MIME type.');
            }
            $dur = explode('.', (string) $file->getDbLength())[0];
            if ($dur === '') {
                $warnings[] = _('Some episodes may be missing duration metadata.');
            }
            break;
        }

        return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }
}
