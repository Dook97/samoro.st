+++
date = '2025-01-24T19:47:49+01:00'
title = 'Hello World'
+++

english lengidz epic style

Zkouška staticky rendrovaných matematických výrazů za pomoci \(\KaTeX\):

\[
\begin{aligned}
KL(\hat{y} || y) &= \sum_{c=1}^{M}\hat{y}_c \log{\frac{\hat{y}_c}{y_c}} \\
JS(\hat{y} || y) &= \frac{1}{2}(KL(y||\frac{y+\hat{y}}{2}) + KL(\hat{y}||\frac{y+\hat{y}}{2}))
\end{aligned}
\]

Tak jo, to vypadá funkčně :) A co codebloky?

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
printf 'jdi pryc\nvodpal\nahoj rad te tu vidim!\nahoj2\n' | \
    sed -n '/ahoj/{p;q}' | \
    xargs cowsay
```

```
ahoj
jak to jde?
```
