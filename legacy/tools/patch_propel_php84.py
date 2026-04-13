"""Patch libretime/propel1 for PHP 8.1+ / 8.4 (signatures, return types)."""
import sys
from pathlib import Path

QUERY_OLD = """    /**
     * Executes an SQL statement, returning a result set as a PDOStatement object.
     * Despite its signature here, this method takes a variety of parameters.
     *
     * Overrides PDO::query() to log queries when required
     *
     * @see       http://php.net/manual/en/pdo.query.php for a description of the possible parameters.
     *
     * @return PDOStatement
     */
    public function query()
    {
        if ($this->useDebug) {
            $debug = $this->getDebugSnapshot();
        }

        $args = func_get_args();
        if (version_compare(PHP_VERSION, '5.3', '<')) {
            $return = call_user_func_array(array($this, 'parent::query'), $args);
        } else {
            $return = call_user_func_array('parent::query', $args);
        }

        if ($this->useDebug) {
            $sql = $args[0];
            $this->log($sql, null, __METHOD__, $debug);
            $this->setLastExecutedQuery($sql);
            $this->incrementQueryCount();
        }

        return $return;
    }"""

QUERY_NEW = """    /**
     * Executes an SQL statement, returning a result set as a PDOStatement object.
     * Overrides PDO::query() to log queries when required (PHP 8.0+ PDO signature).
     *
     * @see https://www.php.net/manual/en/pdo.query.php
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->useDebug) {
            $debug = $this->getDebugSnapshot();
        }

        if ($fetchMode === null && count($fetchModeArgs) === 0) {
            $return = parent::query($query);
        } elseif (count($fetchModeArgs) === 0) {
            $return = parent::query($query, $fetchMode);
        } else {
            $return = parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        if ($this->useDebug) {
            $this->log($query, null, __METHOD__, $debug);
            $this->setLastExecutedQuery($query);
            $this->incrementQueryCount();
        }

        return $return;
    }"""

# PHP 8.4 ArrayObject signatures (PropelOnDemandCollection)
ONDEMAND_OLD = """    // ArrayObject methods

    public function append($value)
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function prepend($value)
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function asort()
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function exchangeArray($input)
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function getArrayCopy()
    {
        throw new PropelException('The On Demand Collection does not allow access by offset');
    }

    public function getFlags()
    {
        throw new PropelException('The On Demand Collection does not allow access by offset');
    }

    public function ksort()
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function natcasesort()
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function natsort()
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function setFlags($flags)
    {
        throw new PropelException('The On Demand Collection does not allow acces by offset');
    }

    public function uasort($cmp_function)
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function uksort($cmp_function)
    {
        throw new PropelException('The On Demand Collection is read only');
    }"""

ONDEMAND_NEW = """    // ArrayObject methods (PHP 8.4-compatible signatures)

    public function append(mixed $value): void
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function prepend(mixed $value): void
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function asort(int $flags = SORT_REGULAR): true
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function exchangeArray(object|array $array): array
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function getArrayCopy(): array
    {
        throw new PropelException('The On Demand Collection does not allow access by offset');
    }

    public function getFlags(): int
    {
        throw new PropelException('The On Demand Collection does not allow access by offset');
    }

    public function ksort(int $flags = SORT_REGULAR): true
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function natcasesort(): true
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function natsort(): true
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function setFlags(int $flags): void
    {
        throw new PropelException('The On Demand Collection does not allow access by offset');
    }

    public function uasort(callable $callback): true
    {
        throw new PropelException('The On Demand Collection is read only');
    }

    public function uksort(callable $callback): true
    {
        throw new PropelException('The On Demand Collection is read only');
    }"""


def main() -> None:
    if len(sys.argv) > 1:
        legacy = Path(sys.argv[1]).resolve()
    else:
        legacy = Path(__file__).resolve().parent.parent

    p = legacy / "vendor/libretime/propel1/runtime/lib/config/PropelConfiguration.php"
    if p.is_file():
        t = p.read_text(encoding="utf-8", errors="replace")
        pairs = [
            (
                "public function offsetExists($offset)",
                "public function offsetExists(mixed $offset): bool",
            ),
            (
                "public function offsetSet($offset, $value)",
                "public function offsetSet(mixed $offset, mixed $value): void",
            ),
            (
                "public function offsetGet($offset)",
                "public function offsetGet(mixed $offset): mixed",
            ),
            (
                "public function offsetUnset($offset)",
                "public function offsetUnset(mixed $offset): void",
            ),
        ]
        changed = False
        for old, new in pairs:
            if old in t and new not in t:
                t = t.replace(old, new, 1)
                changed = True
        if changed:
            p.write_text(t, encoding="utf-8")
            print("patched", p)
        else:
            print("skip config (already patched or unexpected content)")
    else:
        print("skip config (missing file)")

    pdo = legacy / "vendor/libretime/propel1/runtime/lib/connection/PropelPDO.php"
    if pdo.is_file():
        t2 = pdo.read_text(encoding="utf-8", errors="replace")
        if "public function query(string $query" in t2:
            print("skip PropelPDO::query (already patched)")
        elif QUERY_OLD not in t2:
            print("skip PropelPDO::query (unexpected content)", file=sys.stderr)
        else:
            t2 = t2.replace(QUERY_OLD, QUERY_NEW, 1)
            pdo.write_text(t2, encoding="utf-8")
            print("patched", pdo)
    else:
        print("skip PropelPDO (missing file)")

    ondemand = (
        legacy
        / "vendor/libretime/propel1/runtime/lib/collection/PropelOnDemandCollection.php"
    )
    if ondemand.is_file():
        t3 = ondemand.read_text(encoding="utf-8", errors="replace")
        if "public function asort(int $flags" in t3:
            print("skip PropelOnDemandCollection (already patched)")
        elif ONDEMAND_OLD not in t3:
            print(
                "skip PropelOnDemandCollection (unexpected content)",
                file=sys.stderr,
            )
        else:
            t3 = t3.replace(ONDEMAND_OLD, ONDEMAND_NEW, 1)
            ondemand.write_text(t3, encoding="utf-8")
            print("patched", ondemand)
        # count() must match ArrayObject
        t3 = ondemand.read_text(encoding="utf-8", errors="replace")
        if "public function count()" in t3 and "public function count(): int" not in t3:
            t3 = t3.replace(
                "    public function count()\n    {\n        return $this->iterator->count();",
                "    public function count(): int\n    {\n        return $this->iterator->count();",
                1,
            )
            ondemand.write_text(t3, encoding="utf-8")
            print("patched count()", ondemand)
    else:
        print("skip PropelOnDemandCollection (missing file)")

    # Criteria::getIterator() must declare `: Traversable` (PHP 8.4 IteratorAggregate)
    criteria = legacy / "vendor/libretime/propel1/runtime/lib/query/Criteria.php"
    if criteria.is_file():
        tc = criteria.read_text(encoding="utf-8", errors="replace")
        if "public function getIterator()" in tc and "): Traversable" not in tc:
            tc = tc.replace(
                "public function getIterator()",
                "public function getIterator(): \\Traversable",
                1,
            )
            criteria.write_text(tc, encoding="utf-8")
            print("patched Criteria::getIterator()", criteria)
        else:
            print("skip Criteria::getIterator (already patched or not found)")
    else:
        print("skip Criteria (missing file)")



if __name__ == "__main__":
    main()
