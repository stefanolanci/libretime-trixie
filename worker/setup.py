from setuptools import find_packages, setup

version = "0.1.15"  # libretime-trixie fork (Git tag 0.1.15-trixie); not upstream 4.x semver

setup(
    name="libretime-worker",
    version=version,
    description="LibreTime Worker",
    author="LibreTime Contributors",
    url="https://github.com/stefanolanci/libretime-trixie",
    project_urls={
        "Bug Tracker": "https://github.com/stefanolanci/libretime-trixie/issues",
        "Documentation": "https://libretime.org",
        "Source Code": "https://github.com/stefanolanci/libretime-trixie",
    },
    license="MIT",
    packages=find_packages(exclude=["*tests*", "*fixtures*"]),
    entry_points={
        "console_scripts": [
            "libretime-worker=libretime_worker.main:cli",
        ]
    },
    python_requires=">=3.11",
    install_requires=[
        # Celery 4 / kombu 4 pull vine 1.x, which breaks on Python 3.11+ (formatargspec removed).
        "celery>=5.3.6,<6",
        "mutagen>=1.45.1,<1.48",
        "redis>=4.5.0,<6",
        "requests>=2.32.2,<2.33",
    ],
    extras_require={
        "sentry": [
            "sentry-sdk>=1.15.0,<2",
        ],
    },
    zip_safe=False,
)
