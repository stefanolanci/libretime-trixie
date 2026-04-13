from enum import Enum

from django.db import models
from pydantic import BaseModel


class SitePreferences(BaseModel):
    station_name: str


class MessageFormatKind(int, Enum):
    ARTIST_TITLE = 0
    SHOW_ARTIST_TITLE = 1
    RADIO_SHOW = 2


class StreamPreferences(BaseModel):
    input_fade_transition: float
    message_format: MessageFormatKind
    message_offline: str
    replay_gain_enabled: bool
    replay_gain_offset: float

    # input_auto_switch_off: bool
    # input_auto_switch_on: bool
    # input_main_user: str
    # input_main_password: str
    # track_fade_in: float
    # track_fade_out: float
    # track_fade_transition: float


class StreamState(BaseModel):
    input_main_connected: bool
    input_main_streaming: bool
    input_show_connected: bool
    input_show_streaming: bool
    schedule_streaming: bool


class SitePreferenceManager(models.Manager):
    def get_queryset(self):
        return super().get_queryset().filter(user__isnull=True)


class Preference(models.Model):
    user = models.ForeignKey(
        "core.User",
        on_delete=models.CASCADE,
        blank=True,
        null=True,
        db_column="subjid",
    )
    key = models.CharField(
        max_length=255,
        unique=True,
        blank=True,
        null=True,
        db_column="keystr",
    )
    value = models.TextField(
        blank=True,
        null=True,
        db_column="valstr",
    )

    objects = models.Manager()
    site = SitePreferenceManager()

    @classmethod
    def get_site_preferences(cls) -> SitePreferences:
        entries = dict(cls.site.values_list("key", "value"))
        return SitePreferences(
            station_name=entries.get("station_name") or "LibreTime",
        )

    @classmethod
    def get_stream_preferences(cls) -> StreamPreferences:
        entries = dict(cls.site.values_list("key", "value"))
        return StreamPreferences(
            input_fade_transition=float(entries.get("default_transition_fade") or 0.0),
            message_format=MessageFormatKind(
                int(entries.get("stream_label_format") or 0)
            ),
            message_offline=entries.get("off_air_meta") or "Offline",
            replay_gain_enabled=entries.get("enable_replay_gain") == "1",
            replay_gain_offset=float(entries.get("replay_gain_modifier") or 0.0),
        )

    @classmethod
    def get_stream_state(cls) -> StreamState:
        entries = dict(cls.site.values_list("key", "value"))
        # Liquidsoap: harbor is selected when input_*_streaming is true and the harbor buffer
        # has data; otherwise the inner chain (automation) is used. Default "off" when prefs
        # are missing so stations without live DJ hear the schedule.
        return StreamState(
            input_main_connected=entries.get("master_dj") == "true",
            input_main_streaming=entries.get("master_dj_switch", "off") == "on",
            input_show_connected=entries.get("live_dj") == "true",
            input_show_streaming=entries.get("live_dj_switch", "off") == "on",
            # Must match legacy PHP Application_Model_Preference::GetSourceSwitchStatus('scheduled_play'):
            # it always returns 'on' (switch hidden; stream would go silent otherwise). The v2 API used
            # by playout must not read scheduled_play_switch from DB or bootstrap calls stop_schedule and
            # Liquidsoap stays on the default/silence branch while the dashboard still shows automation.
            schedule_streaming=True,
        )

    class Meta:
        managed = False
        db_table = "cc_pref"
        unique_together = (("user", "key"),)
