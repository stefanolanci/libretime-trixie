<?php

require_once 'customvalidators/ConditionalNotEmpty.php';

class Application_Form_TuneInPreferences extends Zend_Form_SubForm
{
    public function init()
    {
        $this->setElementsBelongTo('preferences_tunein');

        $this->setDecorators([
            ['ViewScript', ['viewScript' => 'form/preferences_tunein.phtml']],
        ]);

        $enableTunein = new Zend_Form_Element_Checkbox('enable_tunein');
        $enableTunein->setDecorators([
            'ViewHelper',
            'Errors',
            'Label',
        ]);
        $enableTunein->addDecorator('Label', ['class' => 'enable-tunein']);
        $enableTunein->setLabel(_('Push metadata to your station on TuneIn?'));
        $enableTunein->setValue(Application_Model_Preference::getTuneinEnabled());
        $this->addElement($enableTunein);

        $tuneinStationId = new Zend_Form_Element_Text('tunein_station_id');
        $tuneinStationId->setLabel(_('Station ID:'));
        $tuneinStationId->setValue(Application_Model_Preference::getTuneinStationId());
        $tuneinStationId->setAttrib('class', 'input_text');
        $this->addElement($tuneinStationId);

        $tuneinPartnerKey = new Zend_Form_Element_Text('tunein_partner_key');
        $tuneinPartnerKey->setLabel(_('Partner Key:'));
        $tuneinPartnerKey->setValue(Application_Model_Preference::getTuneinPartnerKey());
        $tuneinPartnerKey->setAttrib('class', 'input_text');
        $this->addElement($tuneinPartnerKey);

        $tuneinPartnerId = new Zend_Form_Element_Text('tunein_partner_id');
        $tuneinPartnerId->setLabel(_('Partner Id:'));
        $tuneinPartnerId->setValue(Application_Model_Preference::getTuneinPartnerId());
        $tuneinPartnerId->setAttrib('class', 'input_text');
        $this->addElement($tuneinPartnerId);
    }

    public function isValid($data)
    {
        if (!is_array($data)) {
            return false;
        }

        if (!parent::isValid($data)) {
            return false;
        }

        $v = $data;
        if (isset($data['preferences_tunein']) && is_array($data['preferences_tunein'])) {
            $v = array_merge($v, $data['preferences_tunein']);
        }

        $enabled = !empty($v['enable_tunein'] ?? null);

        if (!$enabled) {
            return true;
        }

        $credentialsQryStr = '?partnerId=' . $v['tunein_partner_id'] . '&partnerKey=' . $v['tunein_partner_key'] . '&id=' . $v['tunein_station_id'];
        $metadata = Application_Model_Schedule::getCurrentPlayingTrack();

        if (is_null($metadata)) {
            $qryStr = $credentialsQryStr . '&commercial=true';
        } else {
            $metadata['artist'] = empty($metadata['artist']) ? 'n/a' : $metadata['artist'];
            $metadata['title'] = empty($metadata['title']) ? 'n/a' : $metadata['title'];
            $qryStr = $credentialsQryStr . '&artist=' . $metadata['artist'] . '&title=' . $metadata['title'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, TUNEIN_API_URL . $qryStr);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $xmlData = curl_exec($ch);
        $curlErr = curl_error($ch);
        if ($curlErr !== '') {
            Logging::error('Failed to reach TuneIn: ' . curl_errno($ch) . ' - ' . $curlErr . ' - ' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
            $this->getElement('enable_tunein')->setErrors([_('Invalid TuneIn Settings. Please ensure your TuneIn settings are correct and try again.')]);
            curl_close($ch);

            return false;
        }
        curl_close($ch);

        $invalidMsg = _('Invalid TuneIn Settings. Please ensure your TuneIn settings are correct and try again.');
        if ($xmlData === false || $xmlData === '') {
            $this->getElement('enable_tunein')->setErrors([$invalidMsg]);

            return false;
        }

        try {
            libxml_use_internal_errors(true);
            $xmlObj = new SimpleXMLElement($xmlData);
            $status = isset($xmlObj->head->status) ? (string) $xmlObj->head->status : '';
            if ($status !== '200') {
                $this->getElement('enable_tunein')->setErrors([$invalidMsg]);

                return false;
            }
            Application_Model_Preference::setLastTuneinMetadataUpdate(time());
        } catch (Exception $e) {
            Logging::error($e);
            $this->getElement('enable_tunein')->setErrors([$invalidMsg]);

            return false;
        }

        return true;
    }
}
