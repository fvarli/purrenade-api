// Test fixture: reads all of stdin, answers with its length — proves a large
// document is delivered whole through the pipe.
const chunks = []
for await (const chunk of process.stdin) chunks.push(chunk)
process.stdout.write(String(Buffer.concat(chunks).length))
