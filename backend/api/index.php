<?php

declare(strict_types=1);

// Vercel PHP Function entry point. Keep the existing API router as the
// single source of truth so local and preview behavior stay aligned.
require dirname(__DIR__) . '/public/index.php';
