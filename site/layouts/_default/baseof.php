<!DOCTYPE html>
<html lang="{{ .Language }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="initial-scale=1">
  <title>{{ .Site.Title }} | {{ .Title }}</title>
  <link rel="preload" href="/img/solitary_tree_small.webp" as="image" />
  <link rel="preload" href="/style.css" as="style" />
  <link rel="stylesheet" href="/style.css">
  {{ $noop := .WordCount -}} <!-- force content render before hasMath check -->
  {{- if .Page.Store.Get "hasMath" -}}
    <link href="/katex.min.css" rel="stylesheet">
  {{- end }}
</head>
<body>
<div class="row-container">
  <div id="sidepanel">
    <div id="panel-content">
      <div id="panel-top">
        <h1 id="samorost-banner"><a href="{{ relLangURL "" }}">Samoro.st</a></h1>
        <div id="navmenu">
          {{- range site.Menus.main.ByWeight }}
            <a href="{{ replaceRE "index.php$" "" .URL }}"><div class="menuitem">{{ .Name }}</div></a>
          {{- end }}
          <a href="/public/"><div class="menuitem"><pre style="margin: 0;">/public</pre></div></a>
        </div>
        <div id="panel-flags">
          {{ partial "flaglink" . }}
        </div>
      </div>
      <div id="panel-bottom">
        {{ partial "footer" . }}
        <img id="panel-decor" src="/img/vetev.webp" />
      </div>
    </div>
  </div>
  <div id="mainpanel">
    <div id="bgimage-holder"></div>
    <div id="main-content" class="row-item">
      <div>
        {{ block "main" . }}{{ end }}
        <div id='content-footer'>
          {{ block "article-footer" . }}{{ end }}
          <div id='lastedit'>
            <div>{{ T "publish_date" .Date }}</div>
            <div>
              {{ if ne .Date .Lastmod}}
                {{ T "edit_date" .Lastmod }}
              {{ end }}
            </div>
          </div>
        </div>
      </div>
      {{ safeHTML "<?php" }}
        {{ if eq .Lang "cs" }}
        setlocale(LC_TIME, 'cs_CZ.UTF-8');
        {{ end }}

        /* $dsn, $user, $password defs */
        include '/var/www/db.php';

        try {
          $pdo = new PDO($dsn, $username, $password);
          $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

          {{ $postId := ((replaceRE "^/en" "" .RelPermalink) | md5) }}
          $stmt = $pdo->query("SELECT comments_enabled FROM posts WHERE uid = '{{ $postId }}'");
          $com_enable = $stmt->fetch(PDO::FETCH_NUM)[0];
          if (!$com_enable) {
            goto end;
          }

          $stmt = $pdo->query("SELECT author, title, content, date FROM comments WHERE post_id = '{{ $postId }}' ORDER BY date DESC");

          {{ safeHTML "echo <<<HTML" }}
          <div id="comments">
          <h2>{{ T "comments" }}</h2>
          {{ safeHTML "HTML;" }}

          $rowsPresent = false;
          while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rowsPresent = true;
            if (empty($row['content'])) {
              continue;
            }

            $author = empty($row['author']) ? '{{ T "anonymous" }}' : $row['author'];
            $title = $row['title'];
            $content = str_replace("\n", "<br>", $row['content']);
            $date = new DateTime($row['date']);

            $strdate = strftime('%e. %B %Y {{ T "at_time" }} %H:%M', $date->getTimestamp());

            {{ safeHTML "echo <<<ROW" }}
              <div class="comment">
                <span class="comm-hdr"><em>$author</em> <b>$title</b> $strdate</span><br/>
                <span class="comm-bdy">$content</span>
              </div>
            {{ safeHTML "ROW;" }}
          }

          if (!$rowsPresent) {
            echo "<p><em>{{ T "no_comments" }}</em></p>";
          }

          {{ safeHTML "echo <<<HTML" }}
            <form id="comment-form" method="POST" action="{{ .RelPermalink }}">
              <div class="form-inline">
                <input type="text" maxlength="64" name="author" placeholder="{{ T "author" }}" tabindex="1">
                <input type="text" maxlength="128" name="title" placeholder="{{ T "title" }}" tabindex="2">
                <input type="submit" value="{{ T "send" }}" tabindex="4">
              </div>
              <textarea id="content" name="content" rows="10" style="width: 100%;" tabindex="3" placeholder="{{ T "your_message" }}" maxlength="10000" required></textarea>
            </form>
            <div id="char-count"></div>

            <script>
              document.getElementById('comment-form').addEventListener('submit', function() {
                const scrollPosition = window.scrollY || window.pageYOffset;
                sessionStorage.setItem('scrollPos', scrollPosition);
              });

              window.addEventListener('load', function() {
                const savedPos = sessionStorage.getItem('scrollPos');
                if (savedPos !== null) {
                  window.scrollTo(0, parseInt(savedPos, 10));
                  sessionStorage.removeItem('scrollPos');
                }
              });

              document.addEventListener("DOMContentLoaded", function () {
                const textarea = document.getElementById("content");
                const counter = document.getElementById("char-count");
                counter.textContent = "0/10000"
                counter.style.display = "block";

                textarea.addEventListener("input", () => {
                  counter.textContent = textarea.value.length + "/" + textarea.maxLength;
                });
              });
            </script>
          </div>
          {{ safeHTML "HTML;" }}
        } catch (PDOException $e) {
          echo("<div id='db-error'>Connection failed: " . $e->getMessage() . "<p>Please contact administrator: admin@samoro.st</p></div>");
          goto end;
        }
      end:
      {{ safeHTML "?>" }}
    </div>
  </div>
  <div id="main-footer">
    <div id="author-info">
    {{ partial "footer" . }}
    </div>
  </div>
</div>
</body>
</html>
