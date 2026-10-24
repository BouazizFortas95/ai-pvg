const http = require('http');
const fs = require('fs');
const path = require('path');
const server = http.createServer((req, res) => {
  let filePath = req.url === '/' ? 'serve/index.html' : req.url.replace(/^\//, '');
  filePath = path.resolve(__dirname, filePath);
  fs.readFile(filePath, (err, data) => {
    if (err) { res.writeHead(404); res.end('Not Found'); return; }
    const ext = path.extname(filePath);
    const type = ext === '.js' ? 'application/javascript' : ext === '.html' ? 'text/html' : 'text/plain';
    res.writeHead(200, { 'Content-Type': type });
    res.end(data);
  });
});
server.listen(3001, () => console.log('Serve on 3001'));
