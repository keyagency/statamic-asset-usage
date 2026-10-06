<?php

namespace KeyAgency\AssetUsage\Compression;

use RuntimeException;

/** Compressing failed, or would not make the file any smaller. */
class NothingToCompress extends RuntimeException {}
