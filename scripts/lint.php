<?php

// Use the same PHP binary as Composer, including in the compatibility matrix.
$root = dirname(__DIR__); // Resolve paths independently of the command's working directory.
$count = 0; // Count only successfully checked PHP files.
foreach (['src', 'tests', 'scripts'] as $directory) { // Include every first-party PHP directory.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$directory")); // Visit each nested source and test namespace.
    foreach ($files as $file) { // Check each file independently so errors identify the exact path.
        if ($file->getExtension() !== 'php') { // Ignore directories and files in other formats.
            continue; // Leave Markdown, JSON and template syntax to their own consumers.
        }
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); // Pass arguments directly without shell interpolation.
        if (! is_resource($process)) { // Detect a failure to start PHP itself.
            fwrite(STDERR, "Cannot start PHP syntax check.\n"); // Explain why validation could not run.
            exit(1); // Let Composer and CI detect the failed check.
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); // Capture both success and error output.
        fclose($pipes[1]); // Release the child's standard-output pipe.
        fclose($pipes[2]); // Release the child's standard-error pipe.
        if (proc_close($process) !== 0) { // Wait for completion and check PHP's exit status.
            fwrite(STDERR, $output); // Show the actual parser error.
            exit(1); // Stop at the first invalid file.
        }
        $count++; // Count this completed syntax check.
    }
}
echo "Syntax checks passed for $count PHP files.\n"; // Report one concise result for the entire package.
