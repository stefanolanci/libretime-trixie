"""Patch zf1s/zend-session Zend_Session::setSaveHandler for PHP 8.4+ (SessionHandlerInterface)."""
import sys
from pathlib import Path

BRIDGE = r"""<?php

/**
 * Bridges Zend_Session_SaveHandler_Interface to PHP SessionHandlerInterface (PHP 8.4+).
 */
class Zend_Session_SaveHandler_Php84Bridge implements SessionHandlerInterface
{
    /** @var Zend_Session_SaveHandler_Interface */
    private $inner;

    public function __construct(Zend_Session_SaveHandler_Interface $inner)
    {
        $this->inner = $inner;
    }

    public function open(string $path, string $name): bool
    {
        return (bool) $this->inner->open($path, $name);
    }

    public function close(): bool
    {
        return (bool) $this->inner->close();
    }

    public function read(string $id): string|false
    {
        $data = $this->inner->read($id);
        if ($data === false) {
            return false;
        }
        return (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        return (bool) $this->inner->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        return (bool) $this->inner->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        $r = $this->inner->gc($max_lifetime);
        if ($r === false) {
            return false;
        }
        return (int) $r;
    }
}
"""

SESSION_SNIPPET_OLD = """        $result = session_set_save_handler(
            array(&$saveHandler, 'open'),
            array(&$saveHandler, 'close'),
            array(&$saveHandler, 'read'),
            array(&$saveHandler, 'write'),
            array(&$saveHandler, 'destroy'),
            array(&$saveHandler, 'gc')
            );"""

SESSION_SNIPPET_NEW = """        if (!class_exists('Zend_Session_SaveHandler_Php84Bridge', false)) {
            require_once dirname(__FILE__) . '/Session/SaveHandler/Php84Bridge.php';
        }
        $bridge = new Zend_Session_SaveHandler_Php84Bridge($saveHandler);
        $result = session_set_save_handler($bridge, true);"""


def main() -> None:
    if len(sys.argv) > 1:
        legacy = Path(sys.argv[1]).resolve()
    else:
        legacy = Path(__file__).resolve().parent.parent

    bridge_path = (
        legacy
        / "vendor/zf1s/zend-session/library/Zend/Session/SaveHandler/Php84Bridge.php"
    )
    session_path = legacy / "vendor/zf1s/zend-session/library/Zend/Session.php"

    if not session_path.is_file():
        return

    bridge_path.parent.mkdir(parents=True, exist_ok=True)
    if not bridge_path.is_file() or bridge_path.read_text(encoding="utf-8") != BRIDGE:
        bridge_path.write_text(BRIDGE, encoding="utf-8")
        print("wrote", bridge_path)

    t = session_path.read_text(encoding="utf-8", errors="replace")
    if SESSION_SNIPPET_NEW.splitlines()[1].strip() in t:
        return
    if SESSION_SNIPPET_OLD not in t:
        print("skip session patch: expected snippet not found", session_path, file=sys.stderr)
        return
    t = t.replace(SESSION_SNIPPET_OLD, SESSION_SNIPPET_NEW, 1)
    session_path.write_text(t, encoding="utf-8")
    print("patched", session_path)


if __name__ == "__main__":
    main()
