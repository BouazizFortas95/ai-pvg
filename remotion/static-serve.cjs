const http = require('http');
const fs = require('fs');
const path = require('path');
const server = http.createServer((req, res) => {
  console.log('REQ:', req.url);
  let url = req.url === '/' || req.url === '/index.html' ? 'serve/index.html' : (req.url.startsWith('/') ? req.url.slice(1) : req.url);
  let fp = path.resolve(__dirname, url);
  if (fs.existsSync(fp) && fs.statSync(fp).isFile()) {
    const ext = path.extname(fp);
    const ct = ext === '.js' ? 'application/javascript' : ext === '.html' ? 'text/html' : 'text/plain';
    res.writeHead(200, {'Content-Type': ct});
    fs.createReadStream(fp).pipe(res);
  } else {
    console.log('404 for', fp);
    res.writeHead(404); res.end('Not Found');
  }
});
server.listen(3000, () => console.log('Serve on 3000'));
