<?php

class AutoPlaylistManager
{
    /**
     * @var int minimum seconds between AutoPlaylistTask runs (see TaskManager)
     */
    private static $_AUTOPLAYLIST_POLL_INTERVAL_SECONDS = 60;

    /**
     * Check whether $_AUTOPLAYLIST_POLL_INTERVAL_SECONDS have passed since the last call to
     * buildAutoPlaylist.
     *
     * @return bool true if $_AUTOPLAYLIST_POLL_INTERVAL_SECONDS has passed since the last check
     */
    public static function hasAutoPlaylistPollIntervalPassed()
    {
        $lastPolled = Application_Model_Preference::getAutoPlaylistPollLock();

        return empty($lastPolled) || (microtime(true) > $lastPolled + self::$_AUTOPLAYLIST_POLL_INTERVAL_SECONDS);
    }

    /**
     * Find all shows with autoplaylists who have yet to have their playlists built and added to the schedule.
     */
    public static function buildAutoPlaylist()
    {
        $autoPlaylists = static::_upcomingAutoPlaylistShows();
        foreach ($autoPlaylists as $autoplaylist) {
            // creates a ShowInstance object to build the playlist in from the ShowInstancesQuery Object
            $si = new Application_Model_ShowInstance($autoplaylist->getDbId());
            $playlistid = $si->GetAutoPlaylistId();
            // call the addPlaylist to show function and don't check for user permission to avoid call to non-existant user object
            $sid = $si->getShowId();
            $playlistrepeat = new Application_Model_Show($sid);
            if ($playlistrepeat->getHasOverrideIntroPlaylist()) {
                $introplaylistid = $playlistrepeat->getIntroPlaylistId();
            } else {
                $introplaylistid = Application_Model_Preference::GetIntroPlaylist();
            }

            if ($playlistrepeat->getHasOverrideOutroPlaylist()) {
                $outroplaylistid = $playlistrepeat->getOutroPlaylistId();
            } else {
                $outroplaylistid = Application_Model_Preference::GetOutroPlaylist();
            }

            // we want to check and see if we need to repeat this process until the show is 100% scheduled
            // so we create a while loop and break it immediately if repeat until full isn't enabled
            // otherwise we continue to go through adding playlists, including the intro and outro if enabled
            $full = false;
            $repeatuntilfull = $playlistrepeat->getAutoPlaylistRepeat();
            $tempPercentScheduled = 0;
            $si = new Application_Model_ShowInstance($autoplaylist->getDbId());
            // the intro playlist should be added exactly once
            if ($introplaylistid != null) {
                // Logging::info('adding intro');
                $si->addPlaylistToShowStart($introplaylistid, false);
            }
            while (!$full) {
                // we do not want to try to schedule an empty playlist
                if ($playlistid != null) {
                    $si->addPlaylistToShow($playlistid, false);
                }
                $ps = $si->getPercentScheduled();
                if ($ps > 100) {
                    $full = true;
                } elseif (!$repeatuntilfull) {
                    break;
                }
                // we want to avoid an infinite loop if all of the playlists are null
                if ($playlistid == null) {
                    break;
                }
                // another possible issue would be if the show isn't increasing in length each loop
                // ie if all of the playlists being added are zero lengths this could cause an infinite loop
                if ($tempPercentScheduled == $ps) {
                    break;
                }
                // now reset it to the current percent scheduled
                $tempPercentScheduled = $ps;
            }
            // the outroplaylist is added at the end, it will always overbook
            // shows that have repeat until full enabled because they will
            // never have time remaining for the outroplaylist to be added
            // this is done outside the content loop to avoid a scenario
            // where a time remaining smartblock in a outro playlist
            // prevents the repeat until full from functioning by filling up the show
            if ($outroplaylistid != null) {
                $si->addPlaylistToShow($outroplaylistid, false);
            }
            // Only mark built when something reached cc_schedule; otherwise keep retrying
            // (avoids dead air + permanent skip if the first pass added nothing).
            if (!$si->showEmpty()) {
                $si->setAutoPlaylistBuilt(true);

                if (Application_Model_Preference::getScheduleTrimOverbooked()) {
                    $si->trimOverbooked();
                }
            }
        }
        Application_Model_Preference::setAutoPlaylistPollLock(microtime(true));
    }

    /**
     * Show instances that need autoplaylist materialization in cc_schedule.
     *
     * (1) Starting within the next hour (original behaviour).
     * (2) Already started but not ended, still unbuilt — recovers missed polls (load spike,
     *     PHP error, or TaskManager not run during the pre-start window), which otherwise
     *     left starts > now false forever and the show stayed empty (#3226).
     *
     * @return CcShowInstances[] deduplicated by instance id
     *
     * @see https://github.com/libretime/libretime/issues/3226
     */
    protected static function _upcomingAutoPlaylistShows()
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $future = clone $now;
        $future->add(new DateInterval('PT1H'));

        $upcoming = CcShowInstancesQuery::create()
            ->filterByDbModifiedInstance(false)
            ->filterByDbAutoPlaylistBuilt(false)
            ->useCcShowQuery('a', 'left join')
            ->filterByDbHasAutoPlaylist(true)
            ->endUse()
            ->filterByDbStarts($now, Criteria::GREATER_THAN)
            ->filterByDbStarts($future, Criteria::LESS_THAN)
            ->find();

        $missedWindow = CcShowInstancesQuery::create()
            ->filterByDbModifiedInstance(false)
            ->filterByDbAutoPlaylistBuilt(false)
            ->useCcShowQuery('a', 'left join')
            ->filterByDbHasAutoPlaylist(true)
            ->endUse()
            ->filterByDbStarts($now, Criteria::LESS_EQUAL)
            ->filterByDbEnds($now, Criteria::GREATER_THAN)
            ->find();

        $byId = [];
        foreach ($upcoming as $row) {
            $byId[$row->getDbId()] = $row;
        }
        foreach ($missedWindow as $row) {
            $byId[$row->getDbId()] = $row;
        }

        return array_values($byId);
    }
}
