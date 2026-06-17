<?php
/** Raw HTML escape hatch — verbatim markup for bespoke utility pages. */
$s = $args['s'] ?? [];
echo $s['html'] ?? '';
