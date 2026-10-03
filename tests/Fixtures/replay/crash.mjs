// Test fixture: a crash — non-zero exit, diagnostic on stderr.
process.stderr.write('replay crashed: Error\n')
process.exit(3)
