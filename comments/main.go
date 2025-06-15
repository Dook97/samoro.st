package main

import (
	"bytes"
	"crypto/md5"
	"encoding/hex"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"unicode/utf8"

	"github.com/microcosm-cc/bluemonday"
	"golang.org/x/net/html"

	"database/sql"

	_ "github.com/lib/pq"
)

type cmdArgs struct {
	dbUser     string
	dbPass     string
	dbName     string
	sockPath   string
	dbSockPath string
	notifyMail string
}

type postHandleCtx struct {
	db      *sql.DB
	san     *bluemonday.Policy
	bodysan *bluemonday.Policy
}

const notifTemplate =
`Subject: New comment
From: comments daemon <comments-notify>
To: %s

You recieved a new comment on your post at [%s]. Its contents are:

>>>
%s
<<<

-----------------------------------

Sent by the comments daemon comm.go
`

var args cmdArgs

func sanitizeHTML(input string, san *bluemonday.Policy) (string, error) {
	doc, err := html.Parse(strings.NewReader(input))
	if err != nil {
		return "", err
	}

	var buf bytes.Buffer
	err = html.Render(&buf, doc)
	if err != nil {
		return "", err
	}

	return san.Sanitize(buf.String()), nil
}

func sendNotify(postUrl string, commContent string) {
	if (args.notifyMail == "") {
		return
	}

	cmd := exec.Command("sendmail", args.notifyMail)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		goto err
	}

	go func() {
		io.WriteString(stdin, fmt.Sprintf(notifTemplate, args.notifyMail, postUrl, commContent))
		stdin.Close()
	}()

	if cmd.Run() != nil {
		goto err
	} else {
		return
	}

err:
	fmt.Fprintf(os.Stderr, "failed to send email notification: %v", err)
}

func handlePost(w http.ResponseWriter, r *http.Request, ctx *postHandleCtx) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	err := r.ParseForm()
	if err != nil {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}

	// sanitize request data
	content := [3]string{}
	for i, v := range [...]struct { content string; maxlen int; pol *bluemonday.Policy}{
		{"author",  64,   ctx.san},
		{"title",   128,   ctx.san},
		{"content", 10000, ctx.bodysan},
	} {
		rawtext := r.FormValue(v.content)
		if utf8.RuneCountInString(rawtext) > v.maxlen {
			http.Error(w, "content too large", http.StatusRequestEntityTooLarge)
			return
		}

		content[i], err = sanitizeHTML(rawtext, v.pol)
		if err != nil {
			http.Error(w, "bad request", http.StatusBadRequest)
			return
		}
	}

	err = ctx.db.Ping()
	if err != nil {
		fmt.Fprintf(os.Stderr, "lost connection to database: %v", err)
		w.WriteHeader(http.StatusInternalServerError)
		return
	}

	// postid is hex-represented md5 sum of document relative path
	urlSum := md5.Sum([]byte(strings.TrimPrefix(r.URL.Path, "/en")))
	postid := hex.EncodeToString(urlSum[:])

	var commentsEnabled bool
	row := ctx.db.QueryRow("SELECT comments_enabled FROM posts WHERE uid = $1", postid)
	if err = row.Scan(&commentsEnabled); err != nil {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	if !commentsEnabled {
		// someone's trying to be cheeky sending POST requests by hand instead of through the website
		// let's be a little cheeky in return :)
		http.Error(w, "comments disabled for this page", http.StatusTeapot)
		return
	}

	// comment uid and date are handled automatically by constraints in db
	// this format of query with placeholder values automatically sanitazes input
	query := "INSERT INTO comments (post_id, author, title, content) VALUES ($1, $2, $3, $4)"
	_, err = ctx.db.Exec(query, postid, content[0], content[1], content[2])
	if err != nil {
		fmt.Fprintf(os.Stderr, "couldn't insert data into database: %v\n", err)
		// user error is more likely than server error here, but this isn't entirely accurate
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}

	go sendNotify(r.URL.Path, content[2])
	http.Redirect(w, r, r.URL.Path, http.StatusSeeOther)
}

func parseArgs(args *cmdArgs) error {
	flag.StringVar(&args.dbUser, "db-user", "postgres", "postgresql username")
	flag.StringVar(&args.dbPass, "db-pass", "postgres", "postgresql password")
	flag.StringVar(&args.dbName, "db-name", "", "postgresql database name")
	flag.StringVar(&args.dbSockPath, "db-sock", "/var/run/postgresql/", "postgresql socket directory")
	flag.StringVar(&args.sockPath, "sock", "/run/comm/comm.sock", "path at which UNIX listener socket will be created")
	flag.StringVar(&args.notifyMail, "notify-mail", "", "send notifications of new comments to this address")

	flag.Parse()

	if args.dbName == "" {
		fmt.Fprintf(os.Stderr, "-dbName NAME is required\n\n")
		flag.PrintDefaults()
		return errors.New("args err")
	}

	return nil
}

func main() {
	if parseArgs(&args) != nil {
		os.Exit(1)
	}

	// connect to postgresql db
	var dsn strings.Builder
	dsn.Grow(256)
	for _, v := range [...]string{
		"user=",      args.dbUser,
		" password=", args.dbPass,
		" dbname=",   args.dbName,
		" host=",     args.dbSockPath,
		" sslmode=disable",
	} {
		dsn.WriteString(v)
	}
	db, err := sql.Open("postgres", dsn.String())
	if err != nil {
		panic(err)
	}
	defer db.Close()

	// Ping to check connection
	err = db.Ping()
	if err != nil {
		panic(err)
	}

	// open unix socket for communication with nginx
	sockPath := args.sockPath
	os.Remove(sockPath)
	listener, err := net.Listen("unix", sockPath)
	if err != nil {
		panic(err)
	}
	if err = os.Chmod(sockPath, 0777); err != nil {
		panic(err)
	}

	// html input sanitization policies
	san := bluemonday.NewPolicy()
	san.AllowElements("b", "i")

	bsan := bluemonday.NewPolicy()
	bsan.AllowElements("b", "i", "a")
	bsan.AllowAttrs("href").OnElements("a")
	bsan.AllowTables()

	ctx := &postHandleCtx{
		db:      db,
		san:     san,
		bodysan: bsan,
	}

	http.HandleFunc("/", func (w http.ResponseWriter, r *http.Request) {
		handlePost(w, r, ctx)
	});

	http.Serve(listener, nil)
}
