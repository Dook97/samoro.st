+++
date = '2025-01-24T19:47:49+01:00'
title = 'Hello World'
+++

First post on this blog - english version.

Testing static mathematical formula rendering via \(\KaTeX\) :

\[
\begin{aligned}
KL(\hat{y} || y) &= \sum_{c=1}^{M}\hat{y}_c \log{\frac{\hat{y}_c}{y_c}} \\
JS(\hat{y} || y) &= \frac{1}{2}(KL(y||\frac{y+\hat{y}}{2}) + KL(\hat{y}||\frac{y+\hat{y}}{2}))
\end{aligned}
\]

That seems to be working :) Now how about some codeblocks?

```python
def func(arg):
    print(f"hello world {arg}")
```

```c
int main(void) {
    printf("sus yo?\n");
}
```

```bash
printf 'go away\nbuzz off\nhello nice seeing you!\nhello2\n' | \
    sed -n '/hello/{p;q}' | \
    xargs cowsay
```

```
wassup?
how goes it?
```
