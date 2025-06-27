{{ $nposts := .Get 0}}
{{ $title := .Get 1}}

{{ if not (eq $title "") }}
  <h3 style="margin-bottom: 0.5em;">{{ $title }}:</h3>
  <ul style="margin-top: 0;">
{{ else }}
  <ul>
{{ end }}

{{ $pages := .Site.RegularPages }}
{{ range .Site.Home.Translations }}
  {{ $pages = $pages | lang.Merge .Site.RegularPages }}
{{ end }}

{{ range $pages.ByDate.Reverse | first $nposts  }}
  <li>
    <a href="{{ replaceRE "/index.php$" "" .RelPermalink }}">{{ .LinkTitle }}</a>
    {{ partial "flaglink" . }}
  </li>
{{ end }}
</ul>
