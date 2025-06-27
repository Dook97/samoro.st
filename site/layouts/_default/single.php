{{ define "main" }}
  <h1>{{.Title}}</h1>
  {{- with .Content -}}
    {{ . | replaceRE "(<h[1-9] id=\"([^\"]+)\".+)(</h[1-9]+>)" `<a href="#${2}" class="hanchor">${1}</a> ${3}` | safeHTML }}
  {{- end -}}
{{ end }}

{{ define "article-footer" }}
  <div id="article-navigation">
    <div>
      {{ if ne .PrevInSection nil }}
        {{ with .PrevInSection }}
          <a href="{{ replaceRE "/index.php$" "" .RelPermalink }}" title="{{ .Title }}">
        {{ end }}
      {{ end }}
      {{ T "nav_previous" }}
      {{ if ne .PrevInSection nil }}
        </a>
      {{ end }}
    </div>
    <div>
      {{ if ne .NextInSection nil }}
        {{ with .NextInSection }}
          <a href="{{ replaceRE "/index.php$" "" .RelPermalink }}" title="{{ .Title }}">
        {{ end }}
      {{ end }}
      {{ T "nav_next" }}
      {{ if ne .NextInSection nil }}
        </a>
      {{ end }}
    </div>
  </div>
{{ end }}
