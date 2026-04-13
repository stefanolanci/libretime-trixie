from os import getenv

from kombu import Exchange, Queue
from libretime_shared.config import BaseConfig, GeneralConfig, RabbitMQConfig


class Config(BaseConfig):
    general: GeneralConfig
    rabbitmq: RabbitMQConfig = RabbitMQConfig()


LIBRETIME_CONFIG_FILEPATH = getenv("LIBRETIME_CONFIG_FILEPATH")

config = Config(LIBRETIME_CONFIG_FILEPATH)

# Celery 5 removed the AMQP result backend. Redis matches the key layout expected by
# libretime/celery-php (celery-task-meta-<task_id>) when reading results from PHP.
BROKER_URL = config.rabbitmq.url
CELERY_RESULT_BACKEND = getenv(
    "LIBRETIME_CELERY_RESULT_BACKEND",
    "redis://127.0.0.1:6379/0",
)
CELERY_RESULT_PERSISTENT = True
CELERY_TASK_RESULT_EXPIRES = 900  # Expire task results after 15 minutes
CELERY_RESULT_EXCHANGE = "celeryresults"  # Legacy; unused with Redis backend
CELERY_QUEUES = (
    Queue("celery", exchange=Exchange("celery"), routing_key="celery"),
    Queue("podcast", exchange=Exchange("podcast"), routing_key="podcast"),
    Queue(exchange=Exchange("celeryresults"), auto_delete=True),
)
CELERY_EVENT_QUEUE_EXPIRES = 900  # RabbitMQ x-expire after 15 minutes

# Celery task settings
CELERY_TASK_SERIALIZER = "json"
CELERY_RESULT_SERIALIZER = "json"
CELERY_ACCEPT_CONTENT = ["json"]
CELERY_TIMEZONE = config.general.timezone
