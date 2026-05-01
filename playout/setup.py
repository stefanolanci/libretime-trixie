from setuptools import find_packages, setup

version = "0.1.16"  # libretime-trixie fork (Git tag 0.1.16-trixie); not upstream 4.x semver

setup(
    name="libretime-playout",
    version=version,
    description="LibreTime Playout",
    author="LibreTime Contributors",
    url="https://github.com/stefanolanci/libretime-trixie",
    project_urls={
        "Bug Tracker": "https://github.com/stefanolanci/libretime-trixie/issues",
        "Documentation": "https://libretime.org",
        "Source Code": "https://github.com/stefanolanci/libretime-trixie",
    },
    license="AGPLv3",
    packages=find_packages(exclude=["*tests*", "*fixtures*"]),
    package_data={"": ["**/*.liq", "**/*.liq.j2", "**/*.mp3", "*.types"]},
    entry_points={
        "console_scripts": [
            "libretime-playout=libretime_playout.main:cli",
            "libretime-liquidsoap=libretime_playout.liquidsoap.main:cli",
            "libretime-playout-notify=libretime_playout.notify.main:cli",
        ]
    },
    python_requires=">=3.11",
    install_requires=[
        "jinja2>=3.0.3,<3.2",
        # Match worker/celery 5 (shared venv); kombu 4 is incompatible with Python 3.11+ via vine.
        "kombu>=5.3.4,<6",
        "lxml>=4.5.0,<6.1.0",
        "mutagen>=1.45.1,<1.48",
        "python-dateutil>=2.8.1,<2.10",
        "requests>=2.32.2,<2.33",
        "typing-extensions",
    ],
    extras_require={
        "sentry": [
            "sentry-sdk>=1.15.0,<2",
        ],
    },
    zip_safe=False,
)
