<?php

namespace KeyAgency\AssetUsage\Compression;

use RuntimeException;

/** The original couldn't be kept, so the file is left as it is. */
class BackupFailed extends RuntimeException {}
