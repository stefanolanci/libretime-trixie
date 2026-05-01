<?php

class PreferenceController extends Zend_Controller_Action
{
    public function init()
    {
        // Initialize action controller here
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('server-browse', 'json')
            ->addActionContext('change-stor-directory', 'json')
            ->addActionContext('reload-watch-directory', 'json')
            ->addActionContext('remove-watch-directory', 'json')
            ->addActionContext('is-import-in-progress', 'json')
            ->addActionContext('change-stream-setting', 'json')
            ->addActionContext('get-liquidsoap-status', 'json')
            ->addActionContext('get-admin-password-status', 'json')
            ->initContext();
    }

    public function indexAction()
    {
        $request = $this->getRequest();

        Zend_Layout::getMvcInstance()->assign('parent_page', 'Settings');

        $baseUrl = Config::getBasePath();

        $this->view->headScript()->appendFile(Assets::url('js/airtime/preferences/preferences.js'), 'text/javascript');
        $this->view->statusMsg = '';

        $form = new Application_Form_Preferences();
        $values = [];

        SessionHelper::reopenSessionForWriting();

        if ($request->isPost()) {
            $post = $request->getPost();
            if ($form->isValid($post)) {
                $values = $post;
                if (isset($post['preferences_general']) && is_array($post['preferences_general'])) {
                    $values = array_merge($values, $post['preferences_general']);
                }
                if (isset($post['preferences_tunein']) && is_array($post['preferences_tunein'])) {
                    $values = array_merge($values, $post['preferences_tunein']);
                }

                Application_Model_Preference::SetHeadTitle($values['stationName'], $this->view);
                Application_Model_Preference::SetStationDescription($values['stationDescription']);
                Application_Model_Preference::SetTrackTypeDefault($values['tracktypeDefault']);
                Application_Model_Preference::SetDefaultCrossfadeDuration($values['stationDefaultCrossfadeDuration']);
                Application_Model_Preference::SetDefaultFadeIn($values['stationDefaultFadeIn']);
                Application_Model_Preference::SetDefaultFadeOut($values['stationDefaultFadeOut']);
                Application_Model_Preference::SetPodcastAlbumOverride($values['podcastAlbumOverride']);
                Application_Model_Preference::SetPodcastAutoSmartblock($values['podcastAutoSmartblock']);
                Application_Model_Preference::SetIntroPlaylist($values['introPlaylistSelect']);
                Application_Model_Preference::SetOutroPlaylist($values['outroPlaylistSelect']);
                Application_Model_Preference::SetAllow3rdPartyApi($values['thirdPartyApi']);
                Application_Model_Preference::SetDefaultLocale($values['locale']);
                Application_Model_Preference::SetWeekStartDay($values['weekStartDay']);
                Application_Model_Preference::setScheduleTrimOverbooked($values['scheduleTrimOverbooked']);
                Application_Model_Preference::setRadioPageDisplayLoginButton($values['radioPageLoginButton']);
                Application_Model_Preference::setRadioPageDisabled($values['radioPageDisabled']);
                Application_Model_Preference::SetFeaturePreviewMode($values['featurePreviewMode']);

                $logoUploadElement = $form->getSubForm('preferences_general')->getElement('stationLogo');
                $logoUploadElement->receive();
                $imagePath = $logoUploadElement->getFileName();

                // Only update the image logo if the new logo is non-empty
                if (!empty($imagePath) && $imagePath != '') {
                    Application_Model_Preference::SetStationLogo($imagePath);
                }

                $backgroundUploadElement = $form->getSubForm('preferences_general')->getElement('stationBackgroundImage');
                $backgroundUploadElement->receive();
                $backgroundImagePath = $backgroundUploadElement->getFileName();

                if (!empty($backgroundImagePath) && $backgroundImagePath != '') {
                    Application_Model_Preference::SetRadioPageBackgroundImage($backgroundImagePath);
                }

                Application_Model_Preference::SetRadioPageBackgroundSize($values['stationBackgroundSize'] ?? 'cover');

                Application_Model_Preference::setTuneinEnabled($values['enable_tunein'] ?? '0');
                Application_Model_Preference::setTuneinStationId($values['tunein_station_id'] ?? '');
                Application_Model_Preference::setTuneinPartnerKey($values['tunein_partner_key'] ?? '');
                Application_Model_Preference::setTuneinPartnerId($values['tunein_partner_id'] ?? '');

                $this->view->statusMsg = "<div class='success'>" . _('Preferences updated.') . '</div>';
                $form = new Application_Form_Preferences();
                $this->view->form = $form;
            // $this->_helper->json->sendJson(array("valid"=>"true", "html"=>$this->view->render('preference/index.phtml')));
            } else {
                $this->view->form = $form;
                // $this->_helper->json->sendJson(array("valid"=>"false", "html"=>$this->view->render('preference/index.phtml')));
            }
        }
        $this->view->logoImg = Application_Model_Preference::GetStationLogo();
        $this->view->backgroundImg = Application_Model_Preference::GetRadioPageBackgroundImage();

        $this->view->form = $form;
    }

    public function stationPodcastSettingsAction()
    {
        $this->view->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);

        $values = json_decode($this->getRequest()->getRawBody());

        if (!Application_Model_Preference::getStationPodcastPrivacy() && $values->stationPodcastPrivacy == 1) {
            // Refresh the download key when enabling privacy
            Application_Model_Preference::setStationPodcastDownloadKey();
        }

        // Append sharing token (download key) to Station podcast URL
        $stationPodcast = PodcastQuery::create()->findOneByDbId(Application_Model_Preference::getStationPodcastId());
        $key = Application_Model_Preference::getStationPodcastDownloadKey();
        $url = Config::getPublicUrl() . (((int) $values->stationPodcastPrivacy)
            ? "feeds/station-rss?sharing_token={$key}"
            : 'feeds/station-rss');
        $stationPodcast->setDbUrl($url)->save();
        Application_Model_Preference::setStationPodcastPrivacy($values->stationPodcastPrivacy);

        $this->_helper->json->sendJson(['url' => $url]);
    }

    /**
     * Upload dedicated JPG/PNG cover art for the station RSS feed / Apple Podcasts.
     */
    public function stationPodcastAppleArtworkAction()
    {
        SessionHelper::reopenSessionForWriting();

        $this->view->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);

        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHttpResponseCode(405);
            $this->_helper->json->sendJson(['valid' => false, 'error' => _('Method not allowed')]);

            return;
        }

        if (!SecurityHelper::verifyCSRFToken($this->_getParam('csrf_token'))) {
            Logging::error(__FILE__ . ': Invalid CSRF token');
            $this->_helper->json->sendJson([
                'valid' => false,
                'error' => _('CSRF token did not match.'),
            ]);

            return;
        }

        if (!empty($_FILES['artwork']['error'])) {
            $this->_helper->json->sendJson([
                'valid' => false,
                'error' => $this->podcastArtworkUploadErrorMessage((int) $_FILES['artwork']['error']),
            ]);

            return;
        }

        if (empty($_FILES['artwork']['tmp_name'])) {
            $this->_helper->json->sendJson([
                'valid' => false,
                'error' => _('No file uploaded.'),
            ]);

            return;
        }

        $uploadResult = Application_Model_Preference::setPodcastAppleArtworkFromFile($_FILES['artwork']['tmp_name']);
        if (empty($uploadResult['valid'])) {
            $this->_helper->json->sendJson([
                'valid' => false,
                'error' => $uploadResult['error'] ?? _('Podcast artwork upload failed.'),
            ]);

            return;
        }

        try {
            $payload = Application_Service_PodcastService::getPartialStationPodcastAppleUiPayload();
            $this->_helper->json->sendJson(['valid' => true, 'podcast' => $payload]);
        } catch (Exception $e) {
            Logging::error($e->getMessage());
            $this->_helper->json->sendJson([
                'valid' => false,
                'error' => _('Could not refresh podcast metadata.'),
            ]);
        }
    }

    /** Remove uploaded Apple podcast artwork (feed falls back to station logo). */
    public function removePodcastAppleArtworkAction()
    {
        SessionHelper::reopenSessionForWriting();

        $this->view->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);

        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHttpResponseCode(405);
            $this->_helper->json->sendJson(['valid' => false, 'error' => _('Method not allowed')]);

            return;
        }

        if (!SecurityHelper::verifyCSRFToken($this->_getParam('csrf_token'))) {
            Logging::error(__FILE__ . ': Invalid CSRF token');
            $this->_helper->json->sendJson([
                'valid' => false,
                'error' => _('CSRF token did not match.'),
            ]);

            return;
        }

        Application_Model_Preference::clearPodcastAppleArtwork();

        try {
            $payload = Application_Service_PodcastService::getPartialStationPodcastAppleUiPayload();
            $this->_helper->json->sendJson(['valid' => true, 'podcast' => $payload]);
        } catch (Exception $e) {
            Logging::error($e->getMessage());
            $this->_helper->json->sendJson([
                'valid' => false,
                'error' => _('Could not refresh podcast metadata.'),
            ]);
        }
    }

    private function podcastArtworkUploadErrorMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return _('Podcast artwork exceeds the server upload size limit.');
            case UPLOAD_ERR_PARTIAL:
                return _('Podcast artwork upload was interrupted. Please try again.');
            case UPLOAD_ERR_NO_FILE:
                return _('No file uploaded.');
            default:
                return _('Podcast artwork upload failed.');
        }
    }

    public function directoryConfigAction() {}

    public function removeLogoAction()
    {
        SessionHelper::reopenSessionForWriting();

        $this->view->layout()->disableLayout();
        // Remove reliance on .phtml files to render requests
        $this->_helper->viewRenderer->setNoRender(true);

        if (!SecurityHelper::verifyCSRFToken($this->_getParam('csrf_token'))) {
            Logging::error(__FILE__ . ': Invalid CSRF token');
            $this->_helper->json->sendJson(['jsonrpc' => '2.0', 'valid' => false, 'error' => 'CSRF token did not match.']);

            return;
        }

        Application_Model_Preference::SetStationLogo('');
        $this->_helper->json->sendJson(['valid' => true]);
    }

    public function removeBackgroundImageAction()
    {
        SessionHelper::reopenSessionForWriting();

        $this->view->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);

        if (!SecurityHelper::verifyCSRFToken($this->_getParam('csrf_token'))) {
            Logging::error(__FILE__ . ': Invalid CSRF token');
            $this->_helper->json->sendJson(['jsonrpc' => '2.0', 'valid' => false, 'error' => 'CSRF token did not match.']);

            return;
        }

        Application_Model_Preference::SetRadioPageBackgroundImage('');
        $this->_helper->json->sendJson(['valid' => true]);
    }

    public function streamSettingAction()
    {
        $request = $this->getRequest();

        Zend_Layout::getMvcInstance()->assign('parent_page', 'Settings');

        $this->view->headScript()->appendFile(Assets::url('js/airtime/preferences/streamsetting.js'), 'text/javascript');

        SessionHelper::reopenSessionForWriting();

        $name_map = [
            'ogg' => 'Ogg Vorbis',
            'fdkaac' => 'AAC+',
            'aac' => 'AAC',
            'opus' => 'Opus',
            'mp3' => 'MP3',
        ];

        $num_of_stream = intval(Application_Model_Preference::GetNumOfStreams());
        $form = new Application_Form_StreamSetting();

        $csrf_namespace = new Zend_Session_Namespace('csrf_namespace');
        $csrf_element = new Zend_Form_Element_Hidden('csrf');
        $csrf_element->setValue($csrf_namespace->authtoken)->setRequired('true')->removeDecorator('HtmlTag')->removeDecorator('Label');
        $form->addElement($csrf_element);

        $live_stream_subform = new Application_Form_LiveStreamingPreferences();
        $form->addSubForm($live_stream_subform, 'live_stream_subform');

        // get current settings
        $setting = Application_Model_StreamSetting::getStreamSetting();
        $form->setSetting($setting);

        if ($num_of_stream > MAX_NUM_STREAMS) {
            Logging::error('Your streams count (' . $num_of_stream . ') exceed the maximum, some of them will not be displayed');
            $num_of_stream = MAX_NUM_STREAMS;
        }

        for ($i = 1; $i <= $num_of_stream; ++$i) {
            $subform = new Application_Form_StreamSettingSubForm();
            $subform->setPrefix($i);
            $subform->setSetting($setting);
            $subform->startForm();
            $form->addSubForm($subform, 's' . $i . '_subform');
        }

        $live_stream_subform->updateVariables();
        $form->startFrom();

        $streamFormValid = false;

        if ($request->isPost()) {
            $params = $request->getPost();
            /*
             * jQuery sends the whole form as one urlencoded string in `data`.
             * Fields from Live Broadcasting live in nested keys (live_stream_subform[...]).
             * parse_str() builds the nested arrays PHP/Zend expect; the old explode('=')
             * approach left keys like "live_stream_subform[auto_transition]" as flat names,
             * so setStreamPreferences() never saw auto_transition / auto_switch (and other
             * live-stream fields).
             */
            $values = [];
            if (isset($params['data']) && is_string($params['data'])) {
                parse_str($params['data'], $values);
            }

            if (!isset($values['live_stream_subform']) || !is_array($values['live_stream_subform'])) {
                $values['live_stream_subform'] = [];
            }
            // If checkboxes were ever rendered without belongsTo (legacy HTML), they arrive
            // as top-level auto_*; merge before defaulting nested keys to unchecked.
            foreach (['auto_transition', 'auto_switch'] as $cb) {
                if (array_key_exists($cb, $values) && !array_key_exists($cb, $values['live_stream_subform'])) {
                    $values['live_stream_subform'][$cb] = $values[$cb];
                }
            }
            foreach (array_keys($values) as $flatKey) {
                if (preg_match('/^live_stream_subform\[(auto_transition|auto_switch)\]$/', $flatKey, $m)) {
                    if (!array_key_exists($m[1], $values['live_stream_subform'])) {
                        $values['live_stream_subform'][$m[1]] = $values[$flatKey];
                    }
                    unset($values[$flatKey]);
                }
            }
            foreach (['auto_transition', 'auto_switch'] as $checkbox) {
                if (!array_key_exists($checkbox, $values['live_stream_subform'])) {
                    $values['live_stream_subform'][$checkbox] = '0';
                }
            }
            foreach (['enableReplayGain', 'output_sound_device'] as $checkbox) {
                if (!array_key_exists($checkbox, $values)) {
                    $values[$checkbox] = '0';
                }
            }

            // Validate once only: calling isValid() twice can disagree and JSON would say
            // invalid after a successful save (no reload → looks like "not saved").
            $streamFormValid = $form->isValid($values);

            if ($streamFormValid) {
                // Normalized values after validation (nested subforms correct).
                $v = $form->getValues();
                $liveVals = $v['live_stream_subform'] ?? [];
                $parsedLive = $values['live_stream_subform'] ?? [];
                // Zend_Form::getValues() can omit or mis-report checkboxes on ViewScript subforms.
                // parse_str($params['data']) reflects jQuery.serialize() reliably (verified: DB stayed 0
                // while UI showed success when only getValues() was used).
                foreach (['auto_transition', 'auto_switch'] as $cb) {
                    if (array_key_exists($cb, $parsedLive)) {
                        $liveVals[$cb] = $parsedLive[$cb];
                    } else {
                        $liveVals[$cb] = $liveVals[$cb] ?? '0';
                    }
                }

                $this->setStreamPreferences([
                    'offAirMeta' => $values['offAirMeta'] ?? ($v['offAirMeta'] ?? Application_Model_Preference::getOffAirMeta()),
                    'streamFormat' => $values['streamFormat'] ?? ($v['streamFormat'] ?? Application_Model_Preference::GetStreamLabelFormat()),
                    'master_username' => $liveVals['master_username'] ?? '',
                    'master_password' => $liveVals['master_password'] ?? '',
                    'transition_fade' => $liveVals['transition_fade'] ?? (string) Application_Model_Preference::GetDefaultTransitionFade(),
                    'auto_transition' => $liveVals['auto_transition'] ?? '0',
                    'auto_switch' => $liveVals['auto_switch'] ?? '0',
                ]);

                $enableRg = $values['enableReplayGain'] ?? ($v['enableReplayGain'] ?? '0');
                $rgMod = $values['replayGainModifier'] ?? ($v['replayGainModifier'] ?? Application_Model_Preference::getReplayGainModifier());
                $changeRGenabled = Application_Model_Preference::GetEnableReplayGain() != $enableRg;
                $changeRGmodifier = Application_Model_Preference::getReplayGainModifier() != $rgMod;
                if ($changeRGenabled || $changeRGmodifier) {
                    Application_Model_Preference::SetEnableReplayGain($enableRg);
                    Application_Model_Preference::setReplayGainModifier($rgMod);
                    // The side effects of this function are still required to fill the schedule, we
                    // don't use the returned schedule.
                    Application_Model_Schedule::getSchedule();
                    Application_Model_RabbitMq::SendMessageToPypo('update_schedule', []);
                    // Application_Model_RabbitMq::PushSchedule();
                }

                // store stream update timestamp
                Application_Model_Preference::SetStreamUpdateTimestamp();

                $this->view->statusMsg = "<div class='success'>" . _('Stream Setting Updated.') . '</div>';
            }
        }

        $this->view->num_stream = $num_of_stream;
        $this->view->enable_stream_conf = Application_Model_Preference::GetEnableStreamConf();
        $this->view->form = $form;

        if ($request->isPost()) {
            if ($streamFormValid) {
                $this->_helper->json->sendJson([
                    'valid' => 'true',
                    'html' => $this->view->render('preference/stream-setting.phtml'),
                ]);
            } else {
                $this->_helper->json->sendJson(['valid' => 'false', 'html' => $this->view->render('preference/stream-setting.phtml')]);
            }
        }
    }

    /**
     * Set stream settings preferences.
     *
     * @param array $values stream setting preference values
     */
    private function setStreamPreferences($values)
    {
        Application_Model_Preference::setOffAirMeta($values['offAirMeta']);
        Application_Model_Preference::SetStreamLabelFormat($values['streamFormat']);
        Application_Model_Preference::SetLiveStreamMasterUsername($values['master_username']);
        Application_Model_Preference::SetLiveStreamMasterPassword($values['master_password']);
        Application_Model_Preference::SetDefaultTransitionFade($values['transition_fade']);
        Application_Model_Preference::SetAutoTransition($values['auto_transition']);
        Application_Model_Preference::SetAutoSwitch($values['auto_switch']);
    }

    public function serverBrowseAction()
    {
        $request = $this->getRequest();
        $path = $request->getParam('path', null);

        $result = [];

        if (is_null($path)) {
            $element = [];
            $element['name'] = _('path should be specified');
            $element['isFolder'] = false;
            $element['isError'] = true;
            $result[$path] = $element;
        } else {
            $path .= '/';
            $handle = opendir($path);
            if ($handle !== false) {
                while (false !== ($file = readdir($handle))) {
                    if ($file != '.' && $file != '..') {
                        // only show directories that aren't private.
                        if (is_dir($path . $file) && substr($file, 0, 1) != '.') {
                            $element = [];
                            $element['name'] = $file;
                            $element['isFolder'] = true;
                            $element['isError'] = false;
                            $result[$file] = $element;
                        }
                    }
                }
            }
        }
        ksort($result);
        // returns format serverBrowse is looking for.
        $this->_helper->json->sendJson($result);
    }

    public function isImportInProgressAction()
    {
        $now = time();
        $res = false;
        if (Application_Model_Preference::GetImportTimestamp() + 10 > $now) {
            $res = true;
        }
        $this->_helper->json->sendJson($res);
    }

    public function getLiquidsoapStatusAction()
    {
        $out = [];
        $num_of_stream = intval(Application_Model_Preference::GetNumOfStreams());
        for ($i = 1; $i <= $num_of_stream; ++$i) {
            $status = Application_Model_Preference::getLiquidsoapError($i);
            $status = $status == null ? _('Problem with Liquidsoap...') : $status;
            if (!Application_Model_StreamSetting::getStreamEnabled($i)) {
                $status = 'N/A';
            }
            $out[] = ['id' => $i, 'status' => $status];
        }
        $this->_helper->json->sendJson($out);
    }

    public function getAdminPasswordStatusAction()
    {
        SessionHelper::reopenSessionForWriting();

        $out = [];
        $num_of_stream = intval(Application_Model_Preference::GetNumOfStreams());
        for ($i = 1; $i <= $num_of_stream; ++$i) {
            if (Application_Model_StreamSetting::getAdminPass('s' . $i) == '') {
                $out['s' . $i] = false;
            } else {
                $out['s' . $i] = true;
            }
        }
        $this->_helper->json->sendJson($out);
    }

    public function deleteAllFilesAction()
    {
        $this->view->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);

        if (!SecurityHelper::verifyCSRFToken($this->_getParam('csrf_token'))) {
            Logging::error(__FILE__ . ': Invalid CSRF token');
            $this->_helper->json->sendJson(['jsonrpc' => '2.0', 'valid' => false, 'error' => 'CSRF token did not match.']);

            return;
        }

        // Only admin users should get here through ACL permissioning
        // Only allow POST requests
        $method = $_SERVER['REQUEST_METHOD'];
        if (!($method == 'POST')) {
            $this->getResponse()
                ->setHttpResponseCode(405)
                ->appendBody(_('Request method not accepted') . ": {$method}");

            return;
        }

        $this->deleteFutureScheduleItems();
        $this->deleteStoredFiles();

        $this->getResponse()
            ->setHttpResponseCode(200)
            ->appendBody('OK');
    }

    private function deleteFutureScheduleItems()
    {
        $utcTimezone = new DateTimeZone('UTC');
        $nowDateTime = new DateTime('now', $utcTimezone);
        $scheduleItems = CcScheduleQuery::create()
            ->filterByDbEnds($nowDateTime->format(DEFAULT_TIMESTAMP_FORMAT), Criteria::GREATER_THAN)
            ->find();

        // Delete all the schedule items
        foreach ($scheduleItems as $i) {
            // If this is the currently playing track, cancel the current show
            if ($i->isCurrentItem()) {
                $instanceId = $i->getDbInstanceId();
                $instance = CcShowInstancesQuery::create()->findPk($instanceId);
                $showId = $instance->getDbShowId();

                // From ScheduleController
                $scheduler = new Application_Model_Scheduler();
                $scheduler->cancelShow($showId);
                Application_Model_StoredFile::updatePastFilesIsScheduled();
            }

            $i->delete();
        }
    }

    private function deleteStoredFiles()
    {
        // Delete all files from the database
        $files = CcFilesQuery::create()->find();
        foreach ($files as $file) {
            $storedFile = new Application_Model_StoredFile($file, null);
            // Delete the files quietly to avoid getting Sentry errors for
            // every S3 file we delete.
            $storedFile->delete(true);
        }
    }
}
