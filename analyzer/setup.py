from setuptools import find_packages, setup

version = "4.5.0"  # x-release-please-version

setup(
    name="libretime-analyzer",
    version=version,
    description="Libretime Analyzer",
    author="LibreTime Contributors",
    url="https://github.com/libretime/libretime",
    project_urls={
        "Bug Tracker": "https://github.com/libretime/libretime/issues",
        "Documentation": "https://libretime.org",
        "Source Code": "https://github.com/libretime/libretime",
    },
    license="AGPLv3",
    packages=find_packages(exclude=["*tests*", "*fixtures*"]),
    entry_points={
        "console_scripts": [
            "libretime-analyzer=libretime_analyzer.main:cli",
        ]
    },
    python_requires=">=3.11",
    install_requires=[
        "mutagen>=1.45.1,<1.48",
        "pika>=1.0.0,<1.4",
        "requests>=2.32.2,<2.33",
        "typing_extensions",
    ],
    extras_require={
        "sentry": [
            "sentry-sdk>=1.15.0,<2",
        ],
    },
    zip_safe=False,
)
