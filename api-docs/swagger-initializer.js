window.addEventListener('load', function () {
  window.ui = SwaggerUIBundle({
    url: '../api/v1/openapi.json',          // relative: works at /api-docs/ and at /zamove/api-docs/
    dom_id: '#swagger-ui',
    deepLinking: true,
    tryItOutEnabled: true,                  // "Try it out" is on by default
    displayRequestDuration: true,
    docExpansion: 'list',
    defaultModelsExpandDepth: 1,
    persistAuthorization: false,
    layout: 'BaseLayout',                   // no address bar, so the page only ever shows this API's contract
    presets: [SwaggerUIBundle.presets.apis],
    requestInterceptor: function (req) {
      req.credentials = 'same-origin';      // send the HttpOnly session cookie to the same origin
      return req;
    }
  });
});
