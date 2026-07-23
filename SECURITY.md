# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.0.x | Yes |

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately to `abdelwahied.fx@gmail.com` with:

- what the problem is, and what an attacker gains from it;
- the exact request or input that triggers it;
- the module, `saudi_id_validator` and Drupal core versions you saw it on.

Expect an acknowledgement within a few days and an assessment of exploitability
and severity. If a fix is needed, a release follows and the report is credited
unless you prefer otherwise.

If the issue is in Drupal core rather than this module, follow
[Drupal's own security process](https://www.drupal.org/drupal-security-team/security-team-procedures).

## Three kinds of validation, deliberately separated

Most confusion about this module's security scope comes from conflating these.
They fail differently and they belong to different owners.

### 1. Security validation — this module's responsibility

Protecting the site from hostile input. In scope, and a bug here is a
vulnerability:

- **Domain input** is sanitised and validated before use. A submitted label is
  reduced to its name, length-checked, and restricted to letters, digits and
  hyphens. Malformed input is rejected with `422`, never passed to a socket or
  an HTTP client.
- **No SQL is built from input.** Storage goes through the Entity API and the
  query builder.
- **Output is escaped.** Results render through Twig with autoescaping; there is
  a functional test asserting that an XSS payload in a domain query is escaped.
- **Uploaded certificates** are restricted by extension and size, and are
  written to the private file system when the site has one — they carry a
  national ID and a commercial registration.
- **The API endpoint is permission-gated** (`use domain availability api`) and
  rate limited, by requests per window and by minimum interval.
- **CORS** sends `Access-Control-Allow-Origin` only for configured origins.
- **The authoritative provider's API key is write-only.** It is stored in
  configuration but never rendered back into the settings form, so it cannot be
  read from the page or from View Source; an empty submission keeps the stored
  key. It is also never logged, never placed in an exception, and never written
  into a request URL that gets logged.

### 2. Business validation — this module's responsibility, but not a vulnerability

Rules about who may request what: that an individual holding a `.sa` domain must
be a Saudi citizen, that a company must supply its documents, that a duplicate
request inside the window is refused.

These protect *data quality and policy*, not the site. Getting one wrong is a
bug — report it as a normal issue, not as a security report.

### 3. Identification-number validation — **not** this module

Format, leading digit and checksum for National ID and Iqama numbers come
entirely from `saudi_id_validator`. This module holds no such logic.

A vulnerability in how an identification number is validated belongs to that
module and should be reported against it. A bug in how *this* module *uses* the
answer — for example asking `isValid()` where it should ask `isSaudiCitizen()` —
belongs here.

## What is intentionally out of scope

Being explicit, because these are the reports most likely to be filed and
declined:

- **A well-formed identification number is not proof of identity.** Neither
  module contacts a registry or a government service. Nothing here authenticates
  a person; Nafath verification, where the interface mentions it, is a statement
  about the real-world registration process, not something this code performs.
- **A registration request is not a registration.** The module records intent.
  It does not register a domain, take payment or contact a registrar. There is
  no money path to attack.
- **Availability answers are advisory.** They come from third-party registries
  and can be stale, throttled or wrong. An answer is a lookup result, not a
  reservation.
- **Third-party registry behaviour.** RDAP servers, WHOIS servers and the
  optional authoritative HTTP API are outside this project. If a registry
  returns misleading data, the module reports what it was told or `unknown`. A
  compromised upstream registry is not something this module can detect.
- **Outbound requests are a documented feature.** The module contacts registries
  by design. That it makes outbound connections to hosts derived from IANA's
  bootstrap data is not SSRF: the destinations come from registry data and a
  configured server map, not from user input. If you find a path where *user
  input* selects the destination host, that **is** a vulnerability — please
  report it.
- **Denial of service through legitimate use.** One lookup fans out to every
  configured TLD. That is why the API permission is `restrict access: true` and
  why rate limiting ships enabled. A site that grants the permission to
  anonymous users and disables rate limiting has made a configuration choice.

## Data protection

Registration requests hold personal data: names, a national address, a mobile
number, a national ID or Iqama number, and an uploaded certificate.

- Viewing, managing and deleting requests are three separate permissions, all
  `restrict access: true`.
- Certificates go to the private file system when one is configured. **A site
  handling real requests should configure a private file system** — with only a
  public one, certificate URLs are guessable by anyone who can reach the files
  directory.
- Deleting a request releases its certificate through Drupal's file usage
  tracking.
- The submitter's IP address and user agent are recorded with each request.
  Sites subject to retention rules should account for that.
