<?php

class EmbeddablewidgetsController extends Zend_Controller_Action
{
    public function init() {}

    public function playerAction()
    {
        Zend_Layout::getMvcInstance()->assign('parent_page', 'Widgets');

        $this->view->headLink()->appendStylesheet(Assets::url('css/player-form.css'));
        $this->view->headScript()->appendFile(Assets::url('js/airtime/player/player.js'));

        $form = new Application_Form_Player();

        $apiEnabled = Application_Model_Preference::GetAllow3rdPartyApi();
        $numEnabledStreams = $form->getElement('player_stream_url')->getAttrib('numberOfEnabledStreams');

        if ($numEnabledStreams > 0 && $apiEnabled) {
            $this->view->player_form = $form;
        } else {
            $this->view->player_error_msg = _('To configure and use the embeddable player you must:<br><br>
            1. Enable at least one MP3, AAC, or OGG stream under Settings -> Streams<br>
            2. Enable the Public LibreTime API under Settings -> Preferences');
        }
    }

    public function scheduleAction()
    {
        Zend_Layout::getMvcInstance()->assign('parent_page', 'Widgets');

        $apiEnabled = Application_Model_Preference::GetAllow3rdPartyApi();

        if (!$apiEnabled) {
            $this->view->weekly_schedule_error_msg = _('To use the embeddable weekly schedule widget you must:<br><br>
            Enable the Public LibreTime API under Settings -> Preferences');
        }
    }

    /** @deprecated Removed: Facebook Page tab integration is no longer supported. */
    public function facebookAction()
    {
        $this->featureRemovedResponse();
    }

    /** @deprecated Removed along with facebookAction. */
    public function facebookTabSuccessAction()
    {
        $this->featureRemovedResponse();
    }

    private function featureRemovedResponse(): void
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $this->getResponse()
            ->setHttpResponseCode(410)
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)
            ->setBody('The Facebook embed feature has been removed from LibreTime.');
    }
}
