CREATE TABLE comments (
    uid integer NOT NULL,
    post_id character(32), -- foreign key into posts
    author text NOT NULL,
    title text NOT NULL,
    content text NOT NULL,
    date timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT author_len CHECK ((length(author) <= 512)),
    CONSTRAINT content_len CHECK ((length(content) <= 40000)),
    CONSTRAINT content_non_empty CHECK ((TRIM(BOTH FROM content) <> ''::text)),
    CONSTRAINT title_len CHECK ((length(title) <= 1024))
);


CREATE SEQUENCE comments_uid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE comments_uid_seq OWNED BY comments.uid;


CREATE TABLE posts (
    uid character(32) NOT NULL,
    comments_enabled boolean
);


CREATE SEQUENCE posts_uid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE posts_uid_seq OWNED BY posts.uid;


ALTER TABLE ONLY comments ALTER COLUMN uid SET DEFAULT nextval('comments_uid_seq'::regclass);


ALTER TABLE ONLY posts ALTER COLUMN uid SET DEFAULT nextval('posts_uid_seq'::regclass);


ALTER TABLE ONLY comments
    ADD CONSTRAINT comments_pkey PRIMARY KEY (uid);


ALTER TABLE ONLY posts
    ADD CONSTRAINT posts_pkey PRIMARY KEY (uid);


ALTER TABLE ONLY comments
    ADD CONSTRAINT comments_post_id_fkey FOREIGN KEY (post_id) REFERENCES posts(uid);
