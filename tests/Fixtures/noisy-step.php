<?php

/**
 * A bootstrap step that writes to its own stdout, the way Composer, npm and
 * artisan all do. Nothing it prints may reach the caller's stdout.
 */
fwrite(STDOUT, "installing dependencies\n");

for ($i = 0; $i < 500; $i++) {
    fwrite(STDOUT, "- resolving package $i\n");
}

fwrite(STDERR, "warning: something took a while\n");

exit(0);
