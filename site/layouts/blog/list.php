{{ define "main" }}
  {{ .Content }}
  {{ $fmt := "2. January" }}
  {{ if eq "en" .Lang }}
    {{ $fmt = "January 2" }}
  {{ end }}

  {{ $pages := .Site.RegularPages }}
  {{ range .Site.Home.Translations }}
    {{ $pages = $pages | lang.Merge .Site.RegularPages }}
  {{ end }}

  {{ range $pages.GroupByDate "2006" }}
    <h3 class="list-year">{{ .Key }}</h3>
    <ul class="list-item">
      {{ range .Pages.ByDate.Reverse }}
        <li>
          {{ dateFormat $fmt .Date }} <a href="{{ replaceRE "index.php$" "" .RelPermalink }}">{{ .Name }}</a>
          {{ partial "flaglink" . }}
        </li>
      {{ end }}
    </ul>
  {{ end }}
{{ end }}
