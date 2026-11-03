# Wiki source

These pages are the source of the [GitHub wiki](https://github.com/CptNope/JAT-Calendar-Sync/wiki). Edit them here in a pull request; after merging, copy them into the wiki repository:

```sh
git clone https://github.com/CptNope/JAT-Calendar-Sync.wiki.git
cp docs/wiki/*.md JAT-Calendar-Sync.wiki/ && rm JAT-Calendar-Sync.wiki/README.md
cd JAT-Calendar-Sync.wiki && git add -A && git commit -m "Sync wiki" && git push
```

Links between pages use the page name without `.md` (`[Getting Started](Getting-Started)`), which is how GitHub wikis link. Diagrams are Mermaid and render on GitHub.
