{{ range .AllTranslations }}
	{{ if eq .Lang "cs" }}
	      <a href="{{ replaceRE "/index.php$" "" .RelPermalink }}"><img class="cflag" src="/img/cs_flag.webp" /></a>
	{{ else if eq .Lang "en" }}
	      <a href="{{ replaceRE "/index.php$" "" .RelPermalink }}"><img class="cflag" src="/img/en_flag.webp" /></a>
	{{ else }}
		<a href="{{ replaceRE "/index.php$" "" .RelPermalink }}">{{ .Lang }}</a>
	{{ end }}
{{ end }}
