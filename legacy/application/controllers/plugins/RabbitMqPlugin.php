<?php

class RabbitMqPlugin extends Zend_Controller_Plugin_Abstract
{
    public function dispatchLoopShutdown()
    {
        if (Application_Model_RabbitMq::$doPush) {
            $version = Application_Model_Preference::IncrementScheduleVersion();

            // The side effects of this function are still required to fill the schedule, we
            // don't use the returned schedule.
            Application_Model_Schedule::getSchedule();

            $md = [
                'schedule_version' => $version,
                'action'           => Application_Model_RabbitMq::$pushAction,
            ];
            $affected = Application_Model_RabbitMq::$pushAffectedIds;
            if (!empty($affected)) {
                $md['affected_schedule_ids'] = array_values(array_unique($affected));
            }
            Application_Model_RabbitMq::SendMessageToPypo('update_schedule', $md);

            // Reset for next request
            Application_Model_RabbitMq::$pushAction = 'generic';
            Application_Model_RabbitMq::$pushAffectedIds = [];
        }

        if (memory_get_peak_usage() > 30 * 2 ** 20) {
            Logging::debug('Peak memory usage: '
                . (memory_get_peak_usage() / 1000000)
                . ' MB while accessing URI ' . $_SERVER['REQUEST_URI']);
            Logging::debug('Should try to keep memory footprint under 25 MB');
        }
    }
}
