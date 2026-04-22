from setuptools import find_packages, setup

version = "0.1.10"  # libretime-trixie fork (Git tag 0.1.10-trixie); not upstream 4.x semver

setup(
    name="libretime-api-client",
    version=version,
    description="LibreTime API Client",
    author="LibreTime Contributors",
    url="https://github.com/stefanolanci/libretime-trixie",
    project_urls={
        "Bug Tracker": "https://github.com/stefanolanci/libretime-trixie/issues",
        "Documentation": "https://libretime.org",
        "Source Code": "https://github.com/stefanolanci/libretime-trixie",
    },
    license="AGPLv3",
    packages=find_packages(exclude=["*tests*", "*fixtures*"]),
    package_data={"": ["py.typed"]},
    python_requires=">=3.11",
    install_requires=[
        "python-dateutil>=2.8.1,<2.10",
        "requests>=2.32.2,<2.33",
    ],
    zip_safe=False,
)
