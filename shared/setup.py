from setuptools import find_packages, setup

version = "0.1.9"  # libretime-trixie fork (Git tag v0.1.9-trixie); not upstream 4.x semver

setup(
    name="libretime-shared",
    version=version,
    description="LibreTime Shared",
    url="https://github.com/stefanolanci/libretime-trixie",
    author="LibreTime Contributors",
    license="AGPLv3",
    packages=find_packages(exclude=["*tests*", "*fixtures*"]),
    package_data={"": ["py.typed"]},
    python_requires=">=3.11",
    install_requires=[
        "click>=8.0.4,<8.2",
        "pydantic>=2.5.0,<2.12",
        "pyyaml>=5.3.1,<6.1",
    ],
)
