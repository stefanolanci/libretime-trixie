from setuptools import find_packages, setup

version = "0.1.12"  # libretime-trixie fork (Git tag 0.1.12-trixie); not upstream 4.x semver

setup(
    name="libretime-api",
    version=version,
    description="LibreTime API",
    author="LibreTime Contributors",
    url="https://github.com/stefanolanci/libretime-trixie",
    project_urls={
        "Bug Tracker": "https://github.com/stefanolanci/libretime-trixie/issues",
        "Documentation": "https://libretime.org",
        "Source Code": "https://github.com/stefanolanci/libretime-trixie",
    },
    license="AGPLv3",
    packages=find_packages(exclude=["*tests*", "*fixtures*"]),
    package_data={
        "libretime_api": ["legacy/migrations/sql/*.sql"],
    },
    include_package_data=True,
    python_requires=">=3.11",
    entry_points={
        "console_scripts": [
            "libretime-api=libretime_api.manage:main",
        ]
    },
    install_requires=[
        "django-cors-headers>=3.14.0,<4.5",
        "django-filter>=2.4.0,<24.4",
        "django>=4.2.0,<4.3",
        "djangorestframework>=3.14.0,<3.16",
        "drf-spectacular>=0.22.1,<0.29",
        "requests>=2.32.2,<2.33",
    ],
    extras_require={
        "prod": [
            "gunicorn>=22.0.0,<23.1",
            "psycopg[binary]>=3.1.8,<3.3",
            "uvicorn[standard]>=0.17.6,<0.36.0",
        ],
        "sentry": [
            "sentry-sdk[django]>=1.15.0,<2",
        ],
    },
)
