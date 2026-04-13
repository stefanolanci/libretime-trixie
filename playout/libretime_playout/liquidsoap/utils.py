from typing import Any


def escape_liquidsoap_annotate_value(value: str) -> str:
    """
    Escape a string embedded in Liquidsoap annotate:key="..." metadata.

    Backslashes must be doubled before quotes so the annotate lexer does not truncate
    the request or fail to resolve the URI.
    """
    return (
        value.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", " ")
        .replace("\r", " ")
    )


def quote(value: Any, double=False) -> str:
    """
    Quote and escape strings quotes for liquidsoap.

    Double will escape the quotes twice, this is usually only used for the socket
    communication to liquidsoap.
    """
    if not isinstance(value, str):
        value = str(value)
    escaper = "\\\\" if double else "\\"
    escaped = value.replace('"', f'{escaper}"')
    return f'"{escaped}"'
