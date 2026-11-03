<?php

return [
    // Batas baris untuk ekspor CSV. Lebih dari ini ditolak agar server tidak kehabisan memori.
    'report_max_export_rows' => (int) env('HRM_REPORT_MAX_EXPORT_ROWS', 5000),
];
