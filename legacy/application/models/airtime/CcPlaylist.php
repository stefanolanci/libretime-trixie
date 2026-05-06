<?php

/**
 * Skeleton subclass for representing a row from the 'cc_playlist' table.
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 */
class CcPlaylist extends BaseCcPlaylist
{
    /**
     * Get the [optionally formatted] temporal [utime] column value.
     *
     * @param string $format The date/time format string (date()-style).
     *                       If format is NULL, then the raw DateTime object will be returned.
     *
     * @return mixed Formatted date/time value as string or DateTime object (if format is NULL), NULL if column is NULL
     *
     * @throws propelException - if unable to parse/validate the date/time value
     */
    public function getDbUtime($format = 'Y-m-d H:i:s')
    {
        if ($this->utime === null) {
            return null;
        }

        try {
            $dt = new DateTime($this->utime, new DateTimeZone('UTC'));
        } catch (Exception $x) {
            throw new PropelException('Internally stored date/time/timestamp value could not be converted to DateTime: ' . var_export($this->utime, true), $x);
        }

        if ($format === null) {
            // Because propel.useDateTimeClass is TRUE, we return a DateTime object.
            return $dt;
        }
        if (strpos($format, '%') !== false) {
            throw new PropelException('strftime format not supported anymore');
        }

        return $dt->format($format);
    }

    /**
     * Get the [optionally formatted] temporal [mtime] column value.
     *
     * @param string $format The date/time format string (date()-style).
     *                       If format is NULL, then the raw DateTime object will be returned.
     *
     * @return mixed Formatted date/time value as string or DateTime object (if format is NULL), NULL if column is NULL
     *
     * @throws propelException - if unable to parse/validate the date/time value
     */
    public function getDbMtime($format = 'Y-m-d H:i:s')
    {
        if ($this->mtime === null) {
            return null;
        }

        try {
            $dt = new DateTime($this->mtime, new DateTimeZone('UTC'));
        } catch (Exception $x) {
            throw new PropelException('Internally stored date/time/timestamp value could not be converted to DateTime: ' . var_export($this->mtime, true), $x);
        }

        if ($format === null) {
            // Because propel.useDateTimeClass is TRUE, we return a DateTime object.
            return $dt;
        }
        if (strpos($format, '%') !== false) {
            throw new PropelException('strftime format not supported anymore');
        }

        return $dt->format($format);
    }

    /**
     * Computes the value of the aggregate column length
     * Overridden to provide a default of 00:00:00 if the playlist is empty.
     *
     * @param PropelPDO $con A connection object
     *
     * @return mixed The scalar result from the aggregate query
     */
    public function computeDbLength(PropelPDO $con)
    {
        $sql = <<<'SQL'
WITH pref AS (
    SELECT COALESCE(NULLIF(valstr, ''), '0')::DOUBLE PRECISION AS crossfade_seconds
    FROM cc_pref
    WHERE keystr = 'default_crossfade_duration'
), rows AS (
    SELECT pc.cliplength::INTERVAL AS cliplength,
           pc.trackoffset AS trackoffset,
           row_number() OVER (ORDER BY pc.position, pc.id) AS rn,
           COALESCE((SELECT crossfade_seconds FROM pref LIMIT 1), 0) AS crossfade_seconds
    FROM cc_playlistcontents AS pc
    LEFT JOIN cc_files AS f ON pc.file_id = f.id
    WHERE pc.playlist_id = :p1
      AND (f.file_exists IS NULL OR f.file_exists = TRUE)
      AND pc.cliplength IS NOT NULL
)
SELECT SUM(
    GREATEST(
        cliplength - make_interval(
            secs => CASE
                WHEN rn = 1 THEN 0
                WHEN trackoffset IS NOT NULL AND trackoffset > 0 THEN trackoffset
                ELSE crossfade_seconds
            END
        ),
        INTERVAL '0 seconds'
    )
)
FROM rows
SQL;
        $stmt = $con->prepare($sql);
        $stmt->bindValue(':p1', $this->getDbId());
        $stmt->execute();
        $length = $stmt->fetchColumn();
        if (is_null($length)) {
            $length = '00:00:00';
        }

        return $length;
    }
} // CcPlaylist
